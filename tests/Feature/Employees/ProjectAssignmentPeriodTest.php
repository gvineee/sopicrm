<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\EmployeeProjectAssignment;
use App\Domain\Projects\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Models\AuditEvent;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

pest()->group('employees');

/**
 * Audit A10: „ჩანს პროექტი და პერიოდი, მაგრამ არ ჩანს ამ მინიჭების
 * რედაქტირება/დასრულება/გადაყვანა."
 *
 * Only creation existed, so an assignment entered with the wrong date — or one
 * that simply ended — could never be corrected or closed by any route. This is
 * not a tidiness matter: the assignment period is what decides which project a
 * worked day is attributed to, and therefore which project pays for it.
 */
beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);

    $this->organization = Organization::factory()->create();
    $this->otherOrganization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->hr = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->hr->assignRole('hr');

    $this->client = Client::factory()->create(['organization_id' => $this->organization->id]);
    $this->project = Project::factory()->create([
        'organization_id' => $this->organization->id,
        'client_id' => $this->client->id,
        'manager_user_id' => $this->hr->id,
    ]);

    $this->employee = Employee::factory()->create(['organization_id' => $this->organization->id]);

    $this->assignment = EmployeeProjectAssignment::query()->create([
        'organization_id' => $this->organization->id,
        'employee_id' => $this->employee->id,
        'project_id' => $this->project->id,
        'starts_on' => '2026-09-01',
        'ends_on' => null,
    ]);
});

test('a wrong start date can be corrected, and the change is audited', function () {
    $this->actingAs($this->hr)
        ->put(route('employees.project-assignments.update', [$this->employee, $this->assignment]), [
            'starts_on' => '2026-09-10',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('employees.show', $this->employee));

    CurrentOrganization::set($this->organization->id);

    expect($this->assignment->refresh()->starts_on->toDateString())->toBe('2026-09-10');

    $event = AuditEvent::query()->where('action', 'employees.project_assignment.updated')->sole();

    expect($event->before['starts_on'])->toContain('2026-09-01')
        ->and($event->after['starts_on'])->toContain('2026-09-10');
});

test('an open assignment can be ended', function () {
    expect($this->assignment->ends_on)->toBeNull();

    $this->actingAs($this->hr)
        ->put(route('employees.project-assignments.update', [$this->employee, $this->assignment]), [
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-30',
        ])
        ->assertSessionHasNoErrors();

    CurrentOrganization::set($this->organization->id);

    expect($this->assignment->refresh()->ends_on?->toDateString())->toBe('2026-09-30');
});

test('a period cannot be made to end before it starts', function () {
    $this->actingAs($this->hr)
        ->put(route('employees.project-assignments.update', [$this->employee, $this->assignment]), [
            'starts_on' => '2026-09-20',
            'ends_on' => '2026-09-10',
        ])
        ->assertSessionHasErrors('ends_on');

    CurrentOrganization::set($this->organization->id);

    expect($this->assignment->refresh()->starts_on->toDateString())->toBe('2026-09-01')
        ->and($this->assignment->ends_on)->toBeNull();
});

test('an assignment belonging to a different employee cannot be edited through this profile', function () {
    $otherEmployee = Employee::factory()->create(['organization_id' => $this->organization->id]);

    // Nesting the assignment under the employee in the URL is not a
    // guarantee by itself; the pairing has to be checked.
    $this->actingAs($this->hr)
        ->put(route('employees.project-assignments.update', [$otherEmployee, $this->assignment]), [
            'starts_on' => '2026-01-01',
        ])
        ->assertNotFound();

    CurrentOrganization::set($this->organization->id);
    expect($this->assignment->refresh()->starts_on->toDateString())->toBe('2026-09-01');
});

test('a project from another organization cannot be assigned', function () {
    $outsideProject = Project::factory()->create([
        'organization_id' => $this->otherOrganization->id,
        'manager_user_id' => $this->hr->id,
    ]);

    // `exists:projects,id` alone runs beneath the tenant scope, so it accepted
    // any project uuid at all until the organization predicate was added.
    $this->actingAs($this->hr)
        ->post(route('employees.project-assignments.store', $this->employee), [
            'project_id' => $outsideProject->id,
            'starts_on' => '2026-09-01',
        ])
        ->assertSessionHasErrors('project_id');

    CurrentOrganization::set($this->organization->id);

    expect(EmployeeProjectAssignment::query()->where('project_id', $outsideProject->id)->exists())->toBeFalse();
});

test('someone who cannot manage assignments cannot change a period', function () {
    $worker = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $worker->assignRole('employee');

    $this->actingAs($worker)
        ->put(route('employees.project-assignments.update', [$this->employee, $this->assignment]), [
            'starts_on' => '2026-01-01',
        ])
        ->assertForbidden();

    CurrentOrganization::set($this->organization->id);
    expect($this->assignment->refresh()->starts_on->toDateString())->toBe('2026-09-01');
});
