<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\Team;
use App\Domain\Projects\Models\Client;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Tasks\Models\ChecklistItem;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskAssignee;
use App\Domain\Tasks\Models\TaskDependency;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Tasks\Concerns\BuildsAcceptanceFixture;

pest()->group('tasks')->use(BuildsAcceptanceFixture::class);

/**
 * Audit A08: „შექმნაში არის დამატებითი შემსრულებლები, დამოკიდებულებები და
 * checklist-ის დამატება; რედაქტირებაში ეს მართვა არ ჩანს."
 *
 * The edit form accepted scalar fields only, so a crew, a dependency or a
 * checklist entered at creation could never be corrected by any route — a
 * typo in a checklist item meant recreating the task. These tests cover the
 * editor at parity, and the three things that must NOT happen while it gains
 * that power: silently wiping a collection the caller said nothing about,
 * destroying the record of who ticked an item, and letting an edit introduce
 * a dependency cycle.
 */
beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);

    $this->organization = Organization::factory()->create();
    $this->otherOrganization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->client = Client::factory()->create(['organization_id' => $this->organization->id]);

    $this->owner = $this->makeUser('owner');
    $this->managerA = $this->makeUser('project_manager');
    $this->managerB = $this->makeUser('project_manager');

    $this->brigade = Team::factory()->create(['organization_id' => $this->organization->id, 'name' => 'პირველი ბრიგადა']);
    $this->otherBrigade = Team::factory()->create(['organization_id' => $this->organization->id, 'name' => 'მეორე ბრიგადა']);

    $this->performerUser = $this->makeUser('employee');
    $this->performer = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->performerUser->id,
    ]);
    $this->mate = Employee::factory()->create(['organization_id' => $this->organization->id]);
    $this->secondMate = Employee::factory()->create(['organization_id' => $this->organization->id]);

    $this->project = $this->makeProject('Editor Site');
    $this->task = $this->makeTask($this->project, ['planned_quantity' => '100.00', 'status' => 'draft']);

    $this->basePayload = fn (array $overrides = []) => array_merge([
        'title' => $this->task->title,
        'accountable_owner_employee_id' => $this->performer->id,
        'priority' => 'normal',
    ], $overrides);
});

test('the edit page hands the form the collections it is meant to edit', function () {
    TaskAssignee::query()->create([
        'organization_id' => $this->organization->id,
        'task_id' => $this->task->id,
        'employee_id' => $this->mate->id,
    ]);
    ChecklistItem::query()->create([
        'organization_id' => $this->organization->id,
        'task_id' => $this->task->id,
        'label' => 'ყალიბი შემოწმებულია',
        'is_required' => true,
    ]);
    $other = $this->makeTask($this->project, ['planned_quantity' => '10.00', 'status' => 'draft']);
    TaskDependency::query()->create([
        'organization_id' => $this->organization->id,
        'task_id' => $this->task->id,
        'depends_on_task_id' => $other->id,
    ]);

    // Without the eager loads these blocks are `whenLoaded` and simply absent,
    // which would make the editor render empty sections and then delete the
    // rows it never showed.
    $this->actingAs($this->managerA)
        ->get(route('projects.tasks.edit', [$this->project, $this->task]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tasks/Edit')
            ->where('task.assignees.0.employee_id', $this->mate->id)
            ->where('task.checklist_items.0.label', 'ყალიბი შემოწმებულია')
            ->where('task.dependencies.0.depends_on_task_id', $other->id)
            ->has('teams')
            ->has('existingTasks'));
});

test('the editor can add, replace and clear performers and brigades', function () {
    $this->actingAs($this->managerA)
        ->put(route('projects.tasks.update', [$this->project, $this->task]), ($this->basePayload)([
            'assignee_employee_ids' => [$this->mate->id],
            'assignee_team_ids' => [$this->brigade->id],
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $assignees = TaskAssignee::query()->where('task_id', $this->task->id)->get();

    expect($assignees->pluck('employee_id')->filter()->values()->all())->toBe([$this->mate->id])
        ->and($assignees->pluck('team_id')->filter()->values()->all())->toBe([$this->brigade->id]);

    // Replacing one crew member with another leaves exactly one.
    $this->actingAs($this->managerA)
        ->put(route('projects.tasks.update', [$this->project, $this->task]), ($this->basePayload)([
            'assignee_employee_ids' => [$this->secondMate->id],
            'assignee_team_ids' => [$this->otherBrigade->id],
        ]))
        ->assertSessionHasNoErrors();

    CurrentOrganization::set($this->organization->id);
    $assignees = TaskAssignee::query()->where('task_id', $this->task->id)->get();

    expect($assignees->pluck('employee_id')->filter()->values()->all())->toBe([$this->secondMate->id])
        ->and($assignees->pluck('team_id')->filter()->values()->all())->toBe([$this->otherBrigade->id]);

    // An explicit empty array really does clear the crew.
    $this->actingAs($this->managerA)
        ->put(route('projects.tasks.update', [$this->project, $this->task]), ($this->basePayload)([
            'assignee_employee_ids' => [],
            'assignee_team_ids' => [],
        ]))
        ->assertSessionHasNoErrors();

    CurrentOrganization::set($this->organization->id);
    expect(TaskAssignee::query()->where('task_id', $this->task->id)->count())->toBe(0);
});

test('a payload that says nothing about a collection leaves it alone', function () {
    TaskAssignee::query()->create([
        'organization_id' => $this->organization->id,
        'task_id' => $this->task->id,
        'employee_id' => $this->mate->id,
    ]);
    $item = ChecklistItem::query()->create([
        'organization_id' => $this->organization->id,
        'task_id' => $this->task->id,
        'label' => 'არმატურა',
        'is_required' => true,
    ]);

    // Renaming the task must not disband its crew. "Absent" and "empty" are
    // different answers, and treating them the same is how a partial update
    // quietly destroys data.
    $this->actingAs($this->managerA)
        ->put(route('projects.tasks.update', [$this->project, $this->task]), ($this->basePayload)([
            'title' => 'ახალი სათაური',
        ]))
        ->assertSessionHasNoErrors();

    CurrentOrganization::set($this->organization->id);

    expect($this->task->refresh()->title)->toBe('ახალი სათაური')
        ->and(TaskAssignee::query()->where('task_id', $this->task->id)->count())->toBe(1)
        ->and(ChecklistItem::query()->whereKey($item->id)->exists())->toBeTrue();
});

test('renaming a checklist item keeps the record of who ticked it', function () {
    $item = ChecklistItem::query()->create([
        'organization_id' => $this->organization->id,
        'task_id' => $this->task->id,
        'label' => 'არმატურა შემოწმებულია',
        'is_required' => true,
        'is_checked' => true,
        'checked_by_user_id' => $this->performerUser->id,
        'checked_at' => now()->subDay(),
    ]);

    $this->actingAs($this->managerA)
        ->put(route('projects.tasks.update', [$this->project, $this->task]), ($this->basePayload)([
            'checklist_items' => [
                ['id' => $item->id, 'label' => 'არმატურის ბიჯი შემოწმებულია', 'is_required' => false],
                ['label' => 'ყალიბი დამაგრებულია', 'is_required' => true],
            ],
        ]))
        ->assertSessionHasNoErrors();

    CurrentOrganization::set($this->organization->id);
    $item->refresh();

    // Who confirmed the step and when is evidence, not formatting — a label
    // correction must not silently discard it by deleting and recreating.
    expect($item->label)->toBe('არმატურის ბიჯი შემოწმებულია')
        ->and($item->is_required)->toBeFalse()
        ->and($item->is_checked)->toBeTrue()
        ->and($item->checked_by_user_id)->toBe($this->performerUser->id)
        ->and(ChecklistItem::query()->where('task_id', $this->task->id)->count())->toBe(2);

    // Dropping an item from the list removes it.
    $this->actingAs($this->managerA)
        ->put(route('projects.tasks.update', [$this->project, $this->task]), ($this->basePayload)([
            'checklist_items' => [['id' => $item->id, 'label' => $item->label, 'is_required' => false]],
        ]))
        ->assertSessionHasNoErrors();

    CurrentOrganization::set($this->organization->id);
    expect(ChecklistItem::query()->where('task_id', $this->task->id)->count())->toBe(1);
});

test('dependencies can be added and removed, and a cycle is still refused', function () {
    $upstream = $this->makeTask($this->project, ['planned_quantity' => '10.00', 'status' => 'draft']);

    $this->actingAs($this->managerA)
        ->put(route('projects.tasks.update', [$this->project, $this->task]), ($this->basePayload)([
            'depends_on_task_ids' => [$upstream->id],
        ]))
        ->assertSessionHasNoErrors();

    CurrentOrganization::set($this->organization->id);
    expect(TaskDependency::query()->where('task_id', $this->task->id)->count())->toBe(1);

    // The edit path must not become a way around the cycle check that the
    // dedicated dependency endpoint enforces.
    $this->actingAs($this->managerA)
        ->put(route('projects.tasks.update', [$this->project, $upstream]), [
            'title' => $upstream->title,
            'accountable_owner_employee_id' => $this->performer->id,
            'priority' => 'normal',
            'depends_on_task_ids' => [$this->task->id],
        ])
        ->assertSessionHasErrors('depends_on_task_id');

    CurrentOrganization::set($this->organization->id);
    expect(TaskDependency::query()->where('task_id', $upstream->id)->count())->toBe(0)
        // The refused edit rolled back whole: the original edge is untouched.
        ->and(TaskDependency::query()->where('task_id', $this->task->id)->count())->toBe(1);

    $this->actingAs($this->managerA)
        ->put(route('projects.tasks.update', [$this->project, $this->task]), ($this->basePayload)([
            'depends_on_task_ids' => [],
        ]))
        ->assertSessionHasNoErrors();

    CurrentOrganization::set($this->organization->id);
    expect(TaskDependency::query()->where('task_id', $this->task->id)->count())->toBe(0);
});

test('another tenant\'s employee, team or task cannot be attached through the editor', function () {
    $outsideEmployee = Employee::factory()->create(['organization_id' => $this->otherOrganization->id]);
    $outsideTeam = Team::factory()->create(['organization_id' => $this->otherOrganization->id]);

    $this->actingAs($this->managerA)
        ->put(route('projects.tasks.update', [$this->project, $this->task]), ($this->basePayload)([
            'assignee_employee_ids' => [$outsideEmployee->id],
        ]))
        ->assertSessionHasErrors('assignee_employee_ids.0');

    CurrentOrganization::set($this->organization->id);

    $this->actingAs($this->managerA)
        ->put(route('projects.tasks.update', [$this->project, $this->task]), ($this->basePayload)([
            'assignee_team_ids' => [$outsideTeam->id],
        ]))
        ->assertSessionHasErrors('assignee_team_ids.0');

    CurrentOrganization::set($this->organization->id);
    expect(TaskAssignee::query()->where('task_id', $this->task->id)->count())->toBe(0);
});

test('the editor stays shut once the task has left the editable part of its life', function () {
    $submitted = $this->makeTask($this->project, ['planned_quantity' => '10.00']);
    $this->submitAsPerformer('10.00', $submitted);

    $this->actingAs($this->managerA)
        ->put(route('projects.tasks.update', [$this->project, $submitted]), [
            'title' => 'რედაქტირება განხილვისას',
            'accountable_owner_employee_id' => $this->performer->id,
            'priority' => 'normal',
            'checklist_items' => [],
        ])
        ->assertSessionHasErrors('status');

    CurrentOrganization::set($this->organization->id);

    // TM-09/EV-04: the checklist a reviewer is judging is frozen, and the new
    // collection editing must not become a way around that.
    expect($submitted->refresh()->title)->not->toBe('რედაქტირება განხილვისას');
});

test('the cancelled self-close flag cannot be set from either form', function () {
    expect($this->task->self_close_allowed)->toBeFalse();

    $this->actingAs($this->managerA)
        ->put(route('projects.tasks.update', [$this->project, $this->task]), ($this->basePayload)([
            'self_close_allowed' => true,
        ]))
        ->assertSessionHasNoErrors();

    CurrentOrganization::set($this->organization->id);

    // TM-01 cancelled the carve-out. The column survives as history (§17), so
    // it is not dropped — but no request may switch it back on.
    expect($this->task->refresh()->self_close_allowed)->toBeFalse();

    $this->actingAs($this->owner)
        ->post(route('projects.tasks.store', $this->project), [
            'title' => 'ახალი დავალება',
            'accountable_owner_employee_id' => $this->performer->id,
            'priority' => 'normal',
            'self_close_allowed' => true,
        ])
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    expect(Task::query()->where('title', 'ახალი დავალება')->sole()->self_close_allowed)->toBeFalse();
});

test('A19: a unit and a planned quantity are only accepted together', function () {
    // The reported symptom was a task detail reading „0.00 / — 20": someone had
    // typed the number into the unit box and left the quantity empty, and
    // nothing stopped them.
    $this->actingAs($this->managerA)
        ->put(route('projects.tasks.update', [$this->project, $this->task]), ($this->basePayload)([
            'unit' => '20',
        ]))
        ->assertSessionHasErrors('planned_quantity');

    CurrentOrganization::set($this->organization->id);

    $this->actingAs($this->managerA)
        ->put(route('projects.tasks.update', [$this->project, $this->task]), ($this->basePayload)([
            'planned_quantity' => '50',
        ]))
        ->assertSessionHasErrors('unit');

    CurrentOrganization::set($this->organization->id);

    // Both together is fine, and so is neither — a task that is not measured
    // by volume is a normal thing, not an incomplete form.
    $this->actingAs($this->managerA)
        ->put(route('projects.tasks.update', [$this->project, $this->task]), ($this->basePayload)([
            'unit' => 'მ²',
            'planned_quantity' => '50',
        ]))
        ->assertSessionHasNoErrors();

    CurrentOrganization::set($this->organization->id);

    // Clearing both is how a task stops being measured by volume. The form
    // sends explicit nulls for emptied fields; omitting a key entirely means
    // "leave it alone", which is the same rule the collections follow.
    $this->actingAs($this->managerA)
        ->put(route('projects.tasks.update', [$this->project, $this->task]), ($this->basePayload)([
            'unit' => null,
            'planned_quantity' => null,
        ]))
        ->assertSessionHasNoErrors();

    CurrentOrganization::set($this->organization->id);
    expect($this->task->refresh()->unit)->toBeNull()
        ->and($this->task->planned_quantity)->toBeNull();
});
