<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Auth\Models\ProjectMembership;
use App\Domain\Employees\Models\Employee;
use App\Domain\Projects\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * PROJECT-01: Dashboard.vue's Kanban board wires its drag-drop `move` event
 * directly to the SAME existing `projects.tasks.start`/`.submit` routes the
 * desktop Task page already uses (App\Http\Controllers\Tasks\TaskController)
 * — no new backend endpoint was added for the Kanban board itself. What
 * this file actually verifies is the real server-side authorization
 * boundary the Kanban UI depends on (a client-side column mapping is not
 * itself a security boundary — TaskPolicy is), plus the new
 * DashboardController::calendar() endpoint's data scoping, which mirrors
 * the Dashboard's own already-tested visibility rule
 * (tests/Feature/Projects/ProjectTaskWebAccessTest.php's FIX-02/A3 case).
 */
pest()->group('projects', 'tasks');

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

    $this->client = Client::factory()->create(['organization_id' => $this->organization->id]);
    $this->project = Project::factory()->create([
        'organization_id' => $this->organization->id,
        'client_id' => $this->client->id,
        'manager_user_id' => $this->owner->id,
    ]);
});

test('a plain employee cannot start or submit a task that is not theirs (the real boundary the Kanban drag relies on)', function () {
    $ownerUser = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $ownerEmployee = Employee::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $ownerUser->id]);

    $bystanderUser = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $bystanderUser->assignRole('employee');

    $task = Task::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'accountable_owner_employee_id' => $ownerEmployee->id,
        'status' => 'assigned',
    ]);

    // The Kanban card for this task would be draggable in the UI regardless
    // of who's viewing — the server must be the actual gate.
    $this->actingAs($bystanderUser)
        ->post(route('projects.tasks.start', [$this->project, $task]))
        ->assertForbidden();

    CurrentOrganization::set($this->organization->id);
    expect($task->refresh()->status)->toBe('assigned');

    $task->update(['status' => 'in_progress']);

    $this->actingAs($bystanderUser)
        ->post(route('projects.tasks.submit', [$this->project, $task]), ['submitted_quantity' => '1'])
        ->assertForbidden();

    CurrentOrganization::set($this->organization->id);
    expect($task->refresh()->status)->toBe('in_progress');
});

test('starting a task from a non-assigned status is rejected server-side, not just hidden client-side', function () {
    $performerUser = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $employee = Employee::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $performerUser->id]);

    $draftTask = Task::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'accountable_owner_employee_id' => $employee->id,
        'status' => 'draft',
    ]);

    $this->actingAs($performerUser)
        ->post(route('projects.tasks.start', [$this->project, $draftTask]))
        ->assertSessionHasErrors('status');

    CurrentOrganization::set($this->organization->id);
    expect($draftTask->refresh()->status)->toBe('draft');
});

test('the tasks calendar only shows a plain employee their own tasks, matching the Dashboard KPI scope', function () {
    $performerUser = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $performerUser->assignRole('employee');
    $employee = Employee::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $performerUser->id]);
    // A plain employee only sees projects they're an active member of (the
    // same rule DashboardController::index() already enforces) — a
    // real-world performer is always added as a member of their own
    // project, so this mirrors that rather than being an artificial gap.
    ProjectMembership::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'user_id' => $performerUser->id,
    ]);

    $otherEmployee = Employee::factory()->create(['organization_id' => $this->organization->id]);

    Task::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'accountable_owner_employee_id' => $employee->id,
        'title' => 'My Own Task',
        'due_at' => now()->addDays(3),
    ]);

    Task::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'accountable_owner_employee_id' => $otherEmployee->id,
        'title' => "Teammate's Task",
        'due_at' => now()->addDays(3),
    ]);

    $this->actingAs($performerUser)
        ->get(route('tasks.calendar'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Projects/Calendar')
            ->where('tasks', fn ($tasks) => collect($tasks)->pluck('title')->all() === ['My Own Task']));
});

test('the tasks calendar shows an owner every task in the organization, matching the Dashboard KPI scope', function () {
    $employee = Employee::factory()->create(['organization_id' => $this->organization->id]);
    $otherEmployee = Employee::factory()->create(['organization_id' => $this->organization->id]);

    Task::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'accountable_owner_employee_id' => $employee->id,
        'title' => 'My Own Task',
        'due_at' => now()->addDays(3),
    ]);

    Task::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'accountable_owner_employee_id' => $otherEmployee->id,
        'title' => "Teammate's Task",
        'due_at' => now()->addDays(3),
    ]);

    $this->actingAs($this->owner)
        ->get(route('tasks.calendar'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Projects/Calendar')
            ->where('tasks', fn ($tasks) => collect($tasks)->pluck('title')->sort()->values()->all() === ['My Own Task', "Teammate's Task"]));
});

test('the tasks calendar never leaks a task from another organization', function () {
    $otherOrg = Organization::factory()->create();
    CurrentOrganization::set($otherOrg->id);
    $otherProject = Project::factory()->create(['organization_id' => $otherOrg->id]);
    Task::factory()->create([
        'organization_id' => $otherOrg->id,
        'project_id' => $otherProject->id,
        'title' => 'Other Org Task',
        'due_at' => now()->addDays(2),
    ]);

    CurrentOrganization::set($this->organization->id);

    $this->actingAs($this->owner)
        ->get(route('tasks.calendar'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Projects/Calendar')
            ->where('tasks', fn ($tasks) => ! collect($tasks)->pluck('title')->contains('Other Org Task')));
});
