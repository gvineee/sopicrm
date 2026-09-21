<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Contractors\Models\Contractor;
use App\Domain\Contractors\Models\ContractorContract;
use App\Domain\Employees\Models\Employee;
use App\Domain\Projects\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Models\AuditEvent;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskAssignee;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

pest()->group('contractors');

beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->owner = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->owner->assignRole('owner');

    $this->finance = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->finance->assignRole('finance');

    $this->client = Client::factory()->create(['organization_id' => $this->organization->id]);
    $this->project = Project::factory()->create([
        'organization_id' => $this->organization->id,
        'client_id' => $this->client->id,
        'manager_user_id' => $this->owner->id,
    ]);
});

test('owner drives a contractor through the full golden path', function () {
    $this->actingAs($this->owner)->post(route('contractors.store'), [
        'name' => 'შპს ოდა-სერვისი',
        'legal_name' => 'შპს ოდა-სერვისი',
        'default_currency' => 'GEL',
        'is_active' => true,
    ])->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $contractor = Contractor::query()->where('name', 'შპს ოდა-სერვისი')->sole();

    $this->actingAs($this->owner)->post(route('contractors.contracts.store', $contractor), [
        'project_id' => $this->project->id,
        'title' => 'ელექტროსამონტაჟო სამუშაოები',
        'rate_type' => 'lump_sum',
        'total_amount' => '1000.00',
        'currency' => 'GEL',
        'starts_on' => now()->toDateString(),
    ])->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $contract = ContractorContract::query()->where('title', 'ელექტროსამონტაჟო სამუშაოები')->sole();
    expect($contract->status)->toBe('draft');

    // Owner creates the contract and, per the self-approval-forbidden rule,
    // cannot approve it themselves even though they hold contracts.approve.
    $this->owner->assignRole('finance');
    $this->actingAs($this->owner)->post(route('contractors.contracts.submit-for-approval', [$contractor, $contract]))->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    expect($contract->refresh()->status)->toBe('pending_approval');

    $this->actingAs($this->owner)->post(route('contractors.contracts.approve', [$contractor, $contract]))->assertForbidden();

    CurrentOrganization::set($this->organization->id);
    expect($contract->refresh()->status)->toBe('pending_approval');

    // A different finance user approves it.
    $this->actingAs($this->finance)->post(route('contractors.contracts.approve', [$contractor, $contract]))->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    expect($contract->refresh()->status)->toBe('active');

    $this->actingAs($this->owner)->post(route('contractors.project-assignments.store', [$contractor, $this->project]), [
        'contract_id' => $contract->id,
        'starts_on' => now()->toDateString(),
    ])->assertRedirect();

    $accountableOwner = Employee::factory()->create(['organization_id' => $this->organization->id]);
    $task = Task::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'accountable_owner_employee_id' => $accountableOwner->id,
    ]);

    $this->actingAs($this->owner)->post(route('projects.tasks.contractor-assignments.store', [$this->project, $task]), [
        'contractor_id' => $contractor->id,
        'contract_id' => $contract->id,
    ])->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $assignee = TaskAssignee::query()->where('task_id', $task->id)->where('contractor_id', $contractor->id)->sole();
    expect($assignee->employee_id)->toBeNull()
        ->and($task->refresh()->accountable_owner_employee_id)->not->toBeNull()
        ->and($task->accountable_owner_employee_id)->not->toBe($contractor->id);

    $this->actingAs($this->owner)->post(route('contractors.acts.store', $contractor), [
        'contract_id' => $contract->id,
        'project_id' => $this->project->id,
        'task_id' => $task->id,
        'description' => 'კაბელის გაყვანა',
        'quantity' => '10.00',
        'attachment_ids' => [],
    ])->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $act = $contract->fresh()->acts()->sole();
    expect($act->status)->toBe('pending_review');

    $this->actingAs($this->owner)->post(route('contractors.acts.accept', [$contractor, $act]), [
        'accepted_quantity' => '10.00',
        'accepted_amount' => '600.00',
    ])->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    expect($act->refresh()->status)->toBe('accepted')
        ->and(AuditEvent::query()->where('action', 'contractors.act.accepted')->exists())->toBeTrue();

    $this->actingAs($this->finance)->post(route('contractors.contracts.payments.store', [$contractor, $contract]), [
        'amount' => '400.00',
        'currency' => 'GEL',
        'paid_at' => now()->toDateString(),
        'method' => 'bank_transfer',
        'request_id' => (string) Str::uuid(),
    ])->assertRedirect();

    $response = $this->actingAs($this->owner)->get(route('contractors.contracts.show', [$contractor, $contract]))->assertOk();
    $response->assertInertia(fn ($page) => $page->component('Contractors/Contracts/Show')
        ->where('contract.outstanding_balance', fn ($value) => (float) $value === 200.0));
});

test('user without contractors permission is forbidden', function () {
    $user = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);

    $this->actingAs($user)->get(route('contractors.index'))->assertForbidden();
    $this->actingAs($user)->get(route('contractors.create'))->assertForbidden();
});

test('contractor route binding cannot cross organization boundaries', function () {
    $otherOrganization = Organization::factory()->create();
    CurrentOrganization::set($otherOrganization->id);
    $foreignContractor = Contractor::query()->create([
        'organization_id' => $otherOrganization->id,
        'name' => 'Foreign Contractor',
        'default_currency' => 'GEL',
        'is_active' => true,
    ]);

    CurrentOrganization::set($this->organization->id);
    $this->actingAs($this->owner)->get(route('contractors.edit', $foreignContractor))->assertNotFound();
});
