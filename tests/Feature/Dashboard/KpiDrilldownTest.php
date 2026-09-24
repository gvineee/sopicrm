<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Auth\Models\ProjectMembership;
use App\Domain\Employees\Models\Employee;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Audit A15 / acceptance NAV-02: every dashboard KPI card used to link to
 * `/projects` with no filter at all, so the number you clicked and the
 * screen you landed on had nothing to do with each other. These tests pin
 * the property that fix depends on — the card's count and the list it opens
 * come from the same query — rather than just asserting the href.
 */
pest()->group('dashboard');

beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->project = Project::factory()->create(['organization_id' => $this->organization->id]);

    $this->manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->manager->assignRole('project_manager');
    ProjectMembership::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'user_id' => $this->manager->id,
    ]);

    $this->owner = Employee::factory()->create(['organization_id' => $this->organization->id]);

    $this->makeTask = function (array $attributes): Task {
        return Task::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'accountable_owner_employee_id' => $this->owner->id,
        ], $attributes));
    };
});

test('each KPI count equals the total of the list its card opens', function () {
    ($this->makeTask)(['status' => 'in_progress', 'due_at' => now()->addWeek()]);
    ($this->makeTask)(['status' => 'assigned', 'due_at' => now()->subDay()]);
    ($this->makeTask)(['status' => 'blocked', 'due_at' => now()->subWeek()]);
    ($this->makeTask)(['status' => 'completed', 'due_at' => now()->subDay()]);
    ($this->makeTask)(['status' => 'cancelled', 'due_at' => now()->subDay()]);

    $kpis = null;
    $this->actingAs($this->manager)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(function ($page) use (&$kpis) {
            $kpis = $page->toArray()['props']['kpis'];
        });

    $filters = [
        'open' => 'open_tasks',
        'overdue' => 'overdue_tasks',
        'completed_30d' => 'completed_last_30_days',
    ];

    foreach ($filters as $filter => $kpiKey) {
        $this->actingAs($this->manager)->get("/tasks-overview?filter={$filter}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Tasks')
                ->where('filter', $filter)
                ->where('pagination.total', $kpis[$kpiKey]));
    }

    // And the numbers themselves are the real ones, not merely
    // self-consistently wrong: 3 open (in_progress + assigned + blocked),
    // 2 overdue of those, 1 completed.
    expect($kpis['open_tasks'])->toBe(3)
        ->and($kpis['overdue_tasks'])->toBe(2)
        ->and($kpis['completed_last_30_days'])->toBe(1);
});

test('the active filter lives in the url so reload and back restore it', function () {
    ($this->makeTask)(['status' => 'assigned', 'due_at' => now()->subDay()]);

    $this->actingAs($this->manager)->get('/tasks-overview?filter=overdue')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filter', 'overdue')
            ->where('title', 'ვადაგადაცილებული დავალებები'));
});

test('an unknown filter falls back to the open list instead of erroring', function () {
    $this->actingAs($this->manager)->get('/tasks-overview?filter=../../etc/passwd')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filter', 'open'));
});

test('the drill-down never shows a task the dashboard itself would hide', function () {
    // A user with no project-wide tasks.tasks.view sees only tasks they
    // perform — the drill-down must narrow identically, or it becomes a way
    // to read a colleague's work that the dashboard would not show.
    $plainMember = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $plainMember->assignRole('employee');
    ProjectMembership::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'user_id' => $plainMember->id,
    ]);

    ($this->makeTask)(['status' => 'assigned', 'due_at' => now()->subDay()]);

    expect($plainMember->can('tasks.tasks.view'))->toBeFalse();

    $this->actingAs($plainMember)->get('/tasks-overview?filter=overdue')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('pagination.total', 0));
});

test('the drill-down requires authentication', function () {
    $this->get('/tasks-overview?filter=open')->assertRedirect(route('login'));
});
