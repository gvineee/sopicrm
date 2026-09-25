<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Actions\AdoptBiostarPersonAction;
use App\Domain\Devices\Actions\SyncBiostarPersonAction;
use App\Domain\Devices\Models\Credential;
use App\Domain\Devices\Models\CredentialAssignment;
use App\Domain\Devices\Models\ExternalIdentifierMapping;
use App\Domain\Employees\Actions\VerifyProvisionalEmployeeAction;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\Team;
use App\Domain\Projects\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Models\AuditEvent;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

pest()->group('devices', 'employees');

/**
 * A person enrolled in BioStar, brought across into the CRM.
 *
 * They arrive as `pending_verification`: real enough that their badge reads
 * are attributed to them from the first swipe, and explicitly not yet a
 * working member of staff. A door enrolment answers "this person can open a
 * door" — it says nothing about which department they belong to or what they
 * may do here, and creating them active would let the access system quietly
 * grant standing in a system it knows nothing about.
 *
 * The identifiers are the point: BioStar's own user id is stored on the
 * employee, because a card can be lost, blocked or reissued and matching a
 * swipe by card alone loses the person the moment their card changes.
 */
beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);

    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->hr = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->hr->assignRole('hr');

    // The real person and card from the live BioStar server.
    $this->person = [
        'user_id' => '2',
        'name' => 'ირაკლი ღვინერია',
        'card_id' => '69410222',
        'card_type' => 'CSN',
    ];

    $this->sync = fn (array $overrides = []) => app(SyncBiostarPersonAction::class)
        ->execute(array_merge($this->person, $overrides), $this->hr);
});

test('a BioStar person becomes an employee awaiting verification, carrying their BioStar id', function () {
    ['employee' => $employee, 'created' => $created] = ($this->sync)();

    expect($created)->toBeTrue()
        ->and($employee->biostar_user_id)->toBe('2')
        ->and($employee->first_name)->toBe('ირაკლი')
        ->and($employee->last_name)->toBe('ღვინერია')
        // Not active. A door enrolment is not a department or a set of
        // permissions, and this status is what makes that visible.
        ->and($employee->status)->toBe(Employee::STATUS_PENDING_VERIFICATION)
        ->and($employee->team_id)->toBeNull()
        // A placeholder that says where they came from, so nobody mistakes it
        // for a real internal code.
        ->and($employee->internal_code)->toBe('BIOSTAR-2');
});

test('their card is registered and pointed at them, so swipes reach the right person', function () {
    ['employee' => $employee] = ($this->sync)();

    // BioStar reports the number in decimal; the CRM stores a normalized
    // decimal canonical identifier, through the same normalizer the event
    // ingest uses rather than a second rule that could drift from it.
    $credential = Credential::query()->where('canonical_identifier', '69410222')->sole();

    expect($credential->card_type)->toBe('CSN');

    $assignment = CredentialAssignment::query()->where('credential_id', $credential->id)->sole();

    expect($assignment->employee_id)->toBe($employee->id)
        // Backdated on purpose: BioStar's event history reaches further back
        // than this import does, and an assignment starting "now" would orphan
        // every swipe before it.
        ->and($assignment->valid_from->isPast())->toBeTrue();
});

test('the BioStar id is a durable link, recorded as confirmed rather than left for triage', function () {
    ['employee' => $employee] = ($this->sync)();

    $mapping = ExternalIdentifierMapping::query()
        ->where('external_type', 'user')
        ->where('external_identifier', '2')
        ->sole();

    // This employee was created FROM that person, so unlike a swipe from an
    // unknown card there is nothing for an administrator to adjudicate.
    expect($mapping->status)->toBe('confirmed')
        ->and($mapping->target_type)->toBe(Employee::class)
        ->and($mapping->target_id)->toBe($employee->id);
});

test('syncing the same person again does not create a second employee', function () {
    ['employee' => $first] = ($this->sync)();
    ['employee' => $second, 'created' => $created] = ($this->sync)();

    expect($created)->toBeFalse()
        ->and($second->id)->toBe($first->id)
        ->and(Employee::query()->count())->toBe(1)
        ->and(CredentialAssignment::query()->count())->toBe(1);
});

test('a later sync never overrules what an administrator has already decided', function () {
    ['employee' => $employee] = ($this->sync)();

    $team = Team::factory()->create(['organization_id' => $this->organization->id]);
    app(VerifyProvisionalEmployeeAction::class)->execute($employee, $team, $this->hr, 'EMP-0001');

    // BioStar keeps calling them whatever it calls them; the CRM has since
    // been told who they actually are.
    ($this->sync)(['name' => 'Something Else']);

    $employee->refresh();

    expect($employee->status)->toBe(Employee::STATUS_ACTIVE)
        ->and($employee->internal_code)->toBe('EMP-0001')
        ->and($employee->first_name)->toBe('ირაკლი');
});

test('verification requires a department, and activates the person', function () {
    ['employee' => $employee] = ($this->sync)();
    $team = Team::factory()->create(['organization_id' => $this->organization->id, 'name' => 'პირველი ბრიგადა']);

    app(VerifyProvisionalEmployeeAction::class)->execute($employee, $team, $this->hr, 'EMP-0007');

    $employee->refresh();

    // The department is the thing that was genuinely unknown, and the thing
    // everything downstream reads.
    expect($employee->status)->toBe(Employee::STATUS_ACTIVE)
        ->and($employee->team_id)->toBe($team->id)
        ->and($employee->internal_code)->toBe('EMP-0007')
        // The upstream link survives verification — that is what makes it
        // durable across a card being reissued.
        ->and($employee->biostar_user_id)->toBe('2');

    $event = AuditEvent::query()->where('action', 'employees.employee.verified')->sole();

    expect($event->before['status'])->toBe(Employee::STATUS_PENDING_VERIFICATION)
        ->and($event->after['status'])->toBe(Employee::STATUS_ACTIVE)
        ->and($event->actor_user_id)->toBe($this->hr->id);
});

test('an already-verified person cannot be verified a second time', function () {
    ['employee' => $employee] = ($this->sync)();
    $team = Team::factory()->create(['organization_id' => $this->organization->id]);

    app(VerifyProvisionalEmployeeAction::class)->execute($employee, $team, $this->hr);

    expect(fn () => app(VerifyProvisionalEmployeeAction::class)->execute($employee->refresh(), $team, $this->hr))
        ->toThrow(ValidationException::class);
});

test('an unverified person cannot be made responsible for work', function () {
    ['employee' => $pending] = ($this->sync)();

    $owner = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $owner->assignRole('owner');

    $client = Client::factory()->create(['organization_id' => $this->organization->id]);
    $project = Project::factory()->create([
        'organization_id' => $this->organization->id,
        'client_id' => $client->id,
        'manager_user_id' => $owner->id,
    ]);

    // Nobody has said which department they belong to or what they may do
    // here, so they cannot yet be accountable for a task.
    $this->actingAs($owner)
        ->post(route('projects.tasks.store', $project), [
            'title' => 'დავალება დაუდასტურებელზე',
            'accountable_owner_employee_id' => $pending->id,
            'priority' => 'normal',
        ])
        ->assertSessionHasErrors('accountable_owner_employee_id');

    CurrentOrganization::set($this->organization->id);
    expect(Task::query()->count())->toBe(0);
});

test('an unverified person holds no standing on a task even with an account', function () {
    ['employee' => $pending] = ($this->sync)();

    $account = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $pending->user_id = $account->id;
    $pending->save();

    $client = Client::factory()->create(['organization_id' => $this->organization->id]);
    $project = Project::factory()->create([
        'organization_id' => $this->organization->id,
        'client_id' => $client->id,
        'manager_user_id' => $account->id,
    ]);
    $task = Task::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $project->id,
        'accountable_owner_employee_id' => $pending->id,
        'status' => 'in_progress',
    ]);

    // Their badge reads are still attributed to them; it is standing in the
    // workflow they do not have yet.
    expect($account->can('submit', $task))->toBeFalse()
        ->and($account->can('work', $task))->toBeFalse();
});

test('a person with no name still imports rather than being dropped', function () {
    ['employee' => $employee] = ($this->sync)(['user_id' => '77', 'name' => null, 'card_id' => null]);

    expect($employee->biostar_user_id)->toBe('77')
        ->and($employee->status)->toBe(Employee::STATUS_PENDING_VERIFICATION)
        // Better an obviously-placeholder name somebody fixes at verification
        // than a person silently missing from the roster.
        ->and(trim("{$employee->first_name} {$employee->last_name}"))->not->toBe('');
});

test('a synced person can be merged into the employee the organization already had', function () {
    // The live case exactly: the same human is „ირაკლი ღვინერია" in the CRM
    // and `irakli gvineria` in BioStar — one person written in two scripts,
    // which no name comparison should be trusted to equate.
    $existing = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'internal_code' => '011',
        'first_name' => 'ირაკლი',
        'last_name' => 'ღვინერია',
        'status' => Employee::STATUS_ACTIVE,
    ]);

    ['employee' => $provisional] = ($this->sync)();

    app(AdoptBiostarPersonAction::class)
        ->execute($provisional, $existing, $this->hr);

    $existing->refresh();

    // The BioStar identity and the card move onto the person the organization
    // already knows...
    expect($existing->biostar_user_id)->toBe('2')
        ->and($existing->internal_code)->toBe('011')
        ->and($existing->status)->toBe(Employee::STATUS_ACTIVE)
        ->and(CredentialAssignment::query()->where('employee_id', $existing->id)->count())->toBe(1)
        // ...and the provisional row is gone, not left behind as a second
        // version of the same person.
        ->and(Employee::query()->whereKey($provisional->id)->exists())->toBeFalse();

    $mapping = ExternalIdentifierMapping::query()->where('external_identifier', '2')->sole();
    expect($mapping->target_id)->toBe($existing->id);
});

test('a later sync recognises the adopted person instead of creating them again', function () {
    $existing = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'first_name' => 'ირაკლი',
        'last_name' => 'ღვინერია',
        'status' => Employee::STATUS_ACTIVE,
    ]);

    ['employee' => $provisional] = ($this->sync)();
    app(AdoptBiostarPersonAction::class)->execute($provisional, $existing, $this->hr);

    ['employee' => $again, 'created' => $created] = ($this->sync)();

    expect($created)->toBeFalse()
        ->and($again->id)->toBe($existing->id)
        ->and(Employee::query()->count())->toBe(1);
});

test('a verified employee is not something a merge may quietly absorb', function () {
    $existing = Employee::factory()->create(['organization_id' => $this->organization->id]);
    ['employee' => $provisional] = ($this->sync)();

    $team = Team::factory()->create(['organization_id' => $this->organization->id]);
    app(VerifyProvisionalEmployeeAction::class)->execute($provisional, $team, $this->hr);

    // Verification means the organization has taken responsibility for this
    // person; folding them into another is not a data-entry correction.
    expect(fn () => app(AdoptBiostarPersonAction::class)
        ->execute($provisional->refresh(), $existing, $this->hr))
        ->toThrow(ValidationException::class);
});
