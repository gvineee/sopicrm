<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Auth\Models\ProjectMembership;
use App\Domain\Companies\Models\Company;
use App\Domain\Employees\Models\Employee;
use App\Domain\Projects\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

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

    $this->performerUser = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    // Spec hard rule (App\Policies\TaskPolicy::submit): submitting for
    // acceptance is ownership-only, never permission-based — the acting
    // user must resolve to the task's own accountable-owner Employee.
    $this->employee = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->performerUser->id,
    ]);
    $this->client = Client::factory()->create(['organization_id' => $this->organization->id]);
});

test('owner can create a project, add a task, and drive it through the full workflow', function () {
    $this->actingAs($this->owner)->post(route('projects.store'), [
        'code' => 'PRJ-100',
        'name' => 'Golden Path Tower',
        'client_id' => $this->client->id,
        'manager_user_id' => $this->owner->id,
        'address' => 'Tbilisi',
        'starts_on' => now()->toDateString(),
    ])->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $project = Project::query()->where('code', 'PRJ-100')->sole();

    $this->actingAs($this->owner)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Projects/Show')->where('project.code', 'PRJ-100'));

    $this->actingAs($this->owner)->post(route('projects.tasks.store', $project), [
        'title' => 'Pour foundation slab',
        'accountable_owner_employee_id' => $this->employee->id,
        'priority' => 'high',
        'requires_photo_evidence' => false,
    ])->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $task = Task::query()->where('title', 'Pour foundation slab')->sole();
    expect($task->status)->toBe('draft');

    $this->actingAs($this->owner)
        ->get(route('projects.tasks.show', [$project, $task]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tasks/Show')
            ->where('task.status', 'draft')
            ->where('task.can.assign', true));

    $this->actingAs($this->owner)->post(route('projects.tasks.assign', [$project, $task]))->assertRedirect();
    $this->actingAs($this->owner)->post(route('projects.tasks.start', [$project, $task]))->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    expect($task->refresh()->status)->toBe('in_progress');

    $this->actingAs($this->performerUser)->post(route('projects.tasks.submit', [$project, $task]), [
        'submitted_quantity' => '10.00',
    ])->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $task->refresh();
    expect($task->status)->toBe('submitted');
    $submission = $task->submissions()->sole();

    $this->actingAs($this->owner)->post(route('projects.tasks.submissions.accept', [$project, $task, $submission]), [
        'accepted_quantity' => '10.00',
    ])->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    expect($task->refresh()->status)->toBe('completed')
        ->and((string) $task->accepted_quantity)->toBe('10.00');
});

test('project index filters by company', function () {
    $companyA = Company::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Company A']);
    $companyB = Company::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Company B']);

    Project::factory()->create([
        'organization_id' => $this->organization->id,
        'company_id' => $companyA->id,
        'client_id' => $this->client->id,
        'manager_user_id' => $this->owner->id,
        'name' => 'Project A',
    ]);
    Project::factory()->create([
        'organization_id' => $this->organization->id,
        'company_id' => $companyB->id,
        'client_id' => $this->client->id,
        'manager_user_id' => $this->owner->id,
        'name' => 'Project B',
    ]);

    $this->actingAs($this->owner)
        ->get(route('projects.index', ['company_id' => $companyA->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Projects/Index')
            ->where('projects', fn ($projects) => collect($projects)->pluck('name')->all() === ['Project A']));
});

test('task index filters by priority', function () {
    $project = Project::factory()->create([
        'organization_id' => $this->organization->id,
        'client_id' => $this->client->id,
        'manager_user_id' => $this->owner->id,
    ]);

    Task::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $project->id,
        'accountable_owner_employee_id' => $this->employee->id,
        'title' => 'Urgent task',
        'priority' => 'urgent',
    ]);
    Task::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $project->id,
        'accountable_owner_employee_id' => $this->employee->id,
        'title' => 'Low priority task',
        'priority' => 'low',
    ]);

    $this->actingAs($this->owner)
        ->get(route('projects.tasks.index', [$project, 'priority' => 'urgent']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Tasks/Index')
            ->where('tasks', fn ($tasks) => collect($tasks)->pluck('title')->all() === ['Urgent task']));
});

test('a project manager without membership cannot see a project they are not a member of', function () {
    $stranger = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $stranger->assignRole('project_manager');

    $project = Project::factory()->create([
        'organization_id' => $this->organization->id,
        'client_id' => $this->client->id,
        'manager_user_id' => $this->owner->id,
    ]);

    $this->actingAs($stranger)->get(route('projects.show', $project))->assertForbidden();
});

test('FIX-02/A2: a project manager only sees projects they are a member of in the index, search and dashboard KPIs, despite holding projects.view', function () {
    $manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $manager->assignRole('project_manager');

    $ownProject = Project::factory()->create([
        'organization_id' => $this->organization->id,
        'client_id' => $this->client->id,
        'manager_user_id' => $this->owner->id,
        'name' => 'Manager Own Project',
        'status' => 'active',
    ]);
    ProjectMembership::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $ownProject->id,
        'user_id' => $manager->id,
    ]);

    $otherProject = Project::factory()->create([
        'organization_id' => $this->organization->id,
        'client_id' => $this->client->id,
        'manager_user_id' => $this->owner->id,
        'name' => 'Someone Elses Project',
        'status' => 'active',
    ]);

    // List: only the membership project appears, never the other one — even
    // though $manager->can('projects.view') is true.
    $this->actingAs($manager)
        ->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Projects/Index')
            ->where('projects', fn ($projects) => collect($projects)->pluck('name')->all() === ['Manager Own Project']));

    // Direct URL to the other project is still denied (already covered
    // above), and it must not be reachable via a search term matching it.
    $this->actingAs($manager)
        ->get(route('projects.index', ['search' => 'Someone']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Projects/Index')
            ->where('projects', fn ($projects) => collect($projects)->pluck('name')->all() === []));

    // Dashboard KPI never counts the project the manager cannot open.
    $this->actingAs($manager)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard')
            ->where('kpis.active_projects', 1)
            ->where('projects', fn ($projects) => collect($projects)->pluck('name')->all() === ['Manager Own Project']));

    // Owner keeps full organization-wide visibility.
    $this->actingAs($this->owner)
        ->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Projects/Index')
            ->where('projects', fn ($projects) => collect($projects)->pluck('name')->sort()->values()->all()
                === collect(['Manager Own Project', 'Someone Elses Project'])->sort()->values()->all()));
});

test('FIX-02/A3: the dashboard never shows a teammate\'s task title to a plain employee who is not its performer', function () {
    $worker = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $worker->assignRole('employee');
    $workerEmployee = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $worker->id,
    ]);

    $project = Project::factory()->create([
        'organization_id' => $this->organization->id,
        'client_id' => $this->client->id,
        'manager_user_id' => $this->owner->id,
    ]);
    ProjectMembership::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $project->id,
        'user_id' => $worker->id,
    ]);

    $ownTask = Task::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $project->id,
        'accountable_owner_employee_id' => $workerEmployee->id,
        'title' => 'My Own Task',
    ]);
    $teammateTask = Task::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $project->id,
        'accountable_owner_employee_id' => $this->employee->id,
        'title' => 'Teammate Only Task',
    ]);

    // Confirms the premise: the worker really is denied the teammate's task
    // on its own page (TaskPolicy::view()).
    $this->actingAs($worker)->get(route('projects.tasks.show', [$project, $teammateTask]))->assertForbidden();

    $this->actingAs($worker)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard')
            ->where('tasks', fn ($tasks) => collect($tasks)->pluck('title')->all() === ['My Own Task']));

    // A project manager on the same project sees both (project-wide
    // tasks.tasks.view) — the scope only narrows users without it.
    $manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $manager->assignRole('project_manager');
    ProjectMembership::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $project->id,
        'user_id' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard')
            ->where('tasks', fn ($tasks) => collect($tasks)->pluck('title')->sort()->values()->all()
                === collect(['My Own Task', 'Teammate Only Task'])->sort()->values()->all()));
});
