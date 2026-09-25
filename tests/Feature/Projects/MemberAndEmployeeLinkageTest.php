<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Auth\Models\ProjectMembership;
use App\Domain\Employees\Models\Employee;
use App\Domain\Projects\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

pest()->group('projects', 'employees');

/**
 * Audit A09: „პროექტის წევრი აირჩევა User-იდან, დავალების პასუხისმგებელი
 * Employee-დან. ორივე განსხვავებული სიაა. საჭიროა ერთმანეთთან დაკავშირებული
 * წევრობისა და დასაქმების მოდელი."
 *
 * They are different lists because they answer different questions — a
 * membership grants access to a project, an Employee is the person employed —
 * and that is not the problem. The problem was that the two shared nothing on
 * screen but a name the operator had to match by eye, and until A11 there was
 * no link between them to show.
 *
 * So the link is what these tests cover: the account list names the employee
 * behind each login, and says so when there is none.
 */
beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);

    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->owner = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'name' => 'ოვნერი',
    ]);
    $this->owner->assignRole('owner');

    $this->client = Client::factory()->create(['organization_id' => $this->organization->id]);
    $this->project = Project::factory()->create([
        'organization_id' => $this->organization->id,
        'client_id' => $this->client->id,
        'manager_user_id' => $this->owner->id,
    ]);
    ProjectMembership::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'user_id' => $this->owner->id,
    ]);
});

test('the member picker names the employee behind each account', function () {
    $staffUser = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'name' => 'n.kartveli',
    ]);
    $employee = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $staffUser->id,
        'first_name' => 'ნინო',
        'last_name' => 'ქართველი',
        'internal_code' => 'EMP-777',
        'position' => 'ბრიგადირი',
    ]);

    $response = $this->actingAs($this->owner)
        ->get(route('projects.show', $this->project))
        ->assertOk();

    $users = collect($response->viewData('page')['props']['availableUsers']);
    $row = $users->firstWhere('id', $staffUser->id);

    // Without this, the operator saw only the login name and had to guess
    // which member of staff it belonged to.
    expect($row['employee_name'])->toBe(trim("{$employee->first_name} {$employee->last_name}"))
        ->and($row['employee_code'])->toBe('EMP-777')
        ->and($row['employee_position'])->toBe('ბრიგადირი');
});

test('an account with no employee record is still offered, and says so', function () {
    $adminOnly = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'name' => 'გარე რევიზორი',
    ]);

    $response = $this->actingAs($this->owner)
        ->get(route('projects.show', $this->project))
        ->assertOk();

    $row = collect($response->viewData('page')['props']['availableUsers'])->firstWhere('id', $adminOnly->id);

    // An administrator or an outside reviewer legitimately has no employee
    // record, so the account is offered — labelled, not hidden.
    expect($row)->not->toBeNull()
        ->and($row['employee_name'])->toBeNull();
});

test('the responsible-person picker says which employees can actually act on the task', function () {
    $withLogin = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => User::factory()->create([
            'organization_id' => $this->organization->id,
            'current_organization_id' => $this->organization->id,
        ])->id,
    ]);
    $withoutLogin = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => null,
    ]);

    $response = $this->actingAs($this->owner)
        ->get(route('projects.tasks.create', $this->project))
        ->assertOk();

    $employees = collect($response->viewData('page')['props']['employees'])->keyBy('id');

    // Making someone responsible for a task when they cannot sign in means
    // they can never start it, submit it, or see it in „ჩემი დღე". The form
    // now says which is which instead of leaving it to be discovered later.
    expect($employees[$withLogin->id]['has_login'])->toBeTrue()
        ->and($employees[$withoutLogin->id]['has_login'])->toBeFalse();
});
