<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Projects\Models\Client;
use App\Domain\Shared\Services\CurrentOrganization;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Tasks\Concerns\BuildsAcceptanceFixture;

pest()->group('projects', 'tasks')->use(BuildsAcceptanceFixture::class);

/**
 * Audit A07: the project page linked to „დავალებების სრული სია და Kanban",
 * and the destination rendered a list and nothing else — the label was simply
 * false. The board component existed but was wired only into the dashboard.
 *
 * These tests hold the two halves of that promise: the project-scoped screen
 * really serves both views, and the board keeps the project scoping and the
 * filters rather than quietly widening to every task in the organization.
 */
beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);

    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->client = Client::factory()->create(['organization_id' => $this->organization->id]);

    $this->owner = $this->makeUser('owner');
    $this->managerA = $this->makeUser('project_manager');
    $this->managerB = $this->makeUser('project_manager');

    $this->performerUser = $this->makeUser('employee');
    $this->performer = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->performerUser->id,
    ]);

    $this->project = $this->makeProject('Workspace Site');
    $this->otherProject = $this->makeProject('Other Site');
});

test('the project task screen serves a list by default and a board on request', function () {
    $task = $this->makeTask($this->project, ['title' => 'კედლის მოპირკეთება', 'planned_quantity' => '10.00']);

    $this->actingAs($this->managerA)
        ->get(route('projects.tasks.index', $this->project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tasks/Index')
            ->where('view', 'list')
            ->has('pagination')
            ->where('tasks.0.id', $task->id));

    CurrentOrganization::set($this->organization->id);

    $this->actingAs($this->managerA)
        ->get(route('projects.tasks.index', $this->project).'?view=kanban')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tasks/Index')
            ->where('view', 'kanban')
            // A board is read whole, so there is no page to report.
            ->where('pagination', null)
            ->where('kanbanTruncated', false)
            ->where('tasks.0.id', $task->id)
            // Without this the board cannot build a single action URL, since
            // every one of them is /projects/{project}/tasks/{task}/…
            ->where('tasks.0.project_id', $this->project->id));
});

test('an unknown view falls back to the list instead of rendering nothing', function () {
    $this->makeTask($this->project, ['planned_quantity' => '10.00']);

    $this->actingAs($this->managerA)
        ->get(route('projects.tasks.index', $this->project).'?view=gantt')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('view', 'list')->has('pagination'));
});

test('the board stays inside its project and honours the filters', function () {
    $mine = $this->makeTask($this->project, ['title' => 'ჩემი დავალება', 'planned_quantity' => '10.00']);
    $blocked = $this->makeTask($this->project, [
        'title' => 'დაბლოკილი დავალება',
        'planned_quantity' => '10.00',
        'status' => 'blocked',
    ]);
    $elsewhere = $this->makeTask($this->otherProject, ['title' => 'სხვისი დავალება', 'planned_quantity' => '10.00']);

    $response = $this->actingAs($this->managerA)
        ->get(route('projects.tasks.index', $this->project).'?view=kanban')
        ->assertOk();

    $ids = collect($response->viewData('page')['props']['tasks'])->pluck('id')->all();

    expect($ids)->toContain($mine->id)
        ->and($ids)->toContain($blocked->id)
        ->and($ids)->not->toContain($elsewhere->id);

    CurrentOrganization::set($this->organization->id);

    // Switching to the board must not quietly drop the filter the operator
    // had applied on the list.
    $filtered = $this->actingAs($this->managerA)
        ->get(route('projects.tasks.index', $this->project).'?view=kanban&status=blocked')
        ->assertOk();

    $filteredIds = collect($filtered->viewData('page')['props']['tasks'])->pluck('id')->all();

    expect($filteredIds)->toBe([$blocked->id]);
});
