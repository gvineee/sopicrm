<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\Team;
use App\Domain\Projects\Models\Client;
use App\Domain\Projects\Services\ProjectActivityFeed;
use App\Domain\Shared\Models\AuditEvent;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Tasks\Models\ChecklistItem;
use App\Domain\Tasks\Models\TaskStatusEvent;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Tasks\Concerns\BuildsAcceptanceFixture;

pest()->group('tasks', 'acceptance-integrity')->use(BuildsAcceptanceFixture::class);

/**
 * 03-Construction-Task-Manager-Spec-KA.md §16 DV-01: „მენეჯერი გასცემს,
 * შემსრულებელი იღებს → ორი ცალკე audit ჩანაწერი; სწორი task revision."
 *
 * The Tasks domain wrote no audit events at all — sixteen Actions and not one
 * call to AuditLogger. Transitions left a `task_status_events` row, which
 * carries from/to/actor/reason and nothing else: no before/after, no request
 * id, no ip. Everything that was not a transition — creating a task, editing
 * its fields, adding a dependency, answering a checklist item — left no trace
 * anywhere at all.
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

    $this->brigade = Team::factory()->create(['organization_id' => $this->organization->id]);

    $this->performerUser = $this->makeUser('employee');
    $this->performer = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->performerUser->id,
    ]);

    $this->project = $this->makeProject('Audit Site');
});

test('DV-01: issuing and receiving a task leave two separate records, each naming its own actor', function () {
    $task = $this->makeTask($this->project, ['planned_quantity' => '10.00', 'status' => 'draft']);

    // The manager issues the work...
    $this->actingAs($this->managerA)
        ->post(route('projects.tasks.assign', [$this->project, $task]))
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);

    // ...and the performer receives it by starting.
    $this->actingAs($this->performerUser)
        ->post(route('projects.tasks.start', [$this->project, $task]))
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);

    $assigned = AuditEvent::query()->where('action', 'tasks.task.assigned')->sole();
    $started = AuditEvent::query()->where('action', 'tasks.task.in_progress')->sole();

    // Two records, not one combined row, and each attributed to the person who
    // actually acted rather than to whoever happened to be logged in last.
    expect($assigned->id)->not->toBe($started->id)
        ->and($assigned->actor_user_id)->toBe($this->managerA->id)
        ->and($started->actor_user_id)->toBe($this->performerUser->id)
        ->and($assigned->before['status'])->toBe('draft')
        ->and($assigned->after['status'])->toBe('assigned')
        ->and($started->before['status'])->toBe('assigned')
        ->and($started->after['status'])->toBe('in_progress');

    // „სწორი task revision": each row carries the revision it describes, and
    // receiving happened after issuing, so its revision is the later one.
    expect($assigned->after['task_id'])->toBe($task->id)
        ->and($assigned->after['task_version'])->toBeInt()
        ->and($started->after['task_version'])->toBeGreaterThan($assigned->after['task_version']);
});

test('the status history and the audit trail are both written, and neither replaces the other', function () {
    $task = $this->makeTask($this->project, ['planned_quantity' => '10.00', 'status' => 'draft']);

    $this->actingAs($this->managerA)
        ->post(route('projects.tasks.assign', [$this->project, $task]))
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);

    // `task_status_events` is the task's own timeline, cheap to read per task;
    // `audit_events` is the organization-wide trail with request id and ip.
    // Recording only one of them would leave a real gap either way.
    expect(TaskStatusEvent::query()->where('task_id', $task->id)->where('to_status', 'assigned')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'tasks.task.assigned')->exists())->toBeTrue();

    $event = AuditEvent::query()->where('action', 'tasks.task.assigned')->sole();

    expect($event->request_id)->not->toBeNull()
        ->and($event->actor_label)->toBe($this->managerA->email);
});

test('creating a task records the terms it was issued on, not merely that a row appeared', function () {
    $this->actingAs($this->owner)
        ->post(route('projects.tasks.store', $this->project), [
            'title' => 'კედლის მოპირკეთება',
            'accountable_owner_employee_id' => $this->performer->id,
            'priority' => 'high',
            'unit' => 'მ²',
            'planned_quantity' => '100',
            'requires_photo_evidence' => true,
            'min_required_photos' => 2,
            'assignee_team_ids' => [$this->brigade->id],
        ])
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);

    $event = AuditEvent::query()->where('action', 'tasks.task.created')->sole();

    expect($event->actor_user_id)->toBe($this->owner->id)
        ->and($event->after['title'])->toBe('კედლის მოპირკეთება')
        ->and($event->after['accountable_owner_employee_id'])->toBe($this->performer->id)
        ->and($event->after['planned_quantity'])->toBe('100.00')
        ->and($event->after['min_required_photos'])->toBe(2)
        // The crew a task was issued to is part of its terms.
        ->and($event->after['assignee_team_ids'])->toBe([$this->brigade->id]);
});

test('editing a task records only what actually changed, with both sides', function () {
    $task = $this->makeTask($this->project, [
        'planned_quantity' => '10.00',
        'status' => 'draft',
        'title' => 'ძველი სათაური',
        'priority' => 'normal',
    ]);

    $this->actingAs($this->managerA)
        ->put(route('projects.tasks.update', [$this->project, $task]), [
            'title' => 'ახალი სათაური',
            'accountable_owner_employee_id' => $this->performer->id,
            'priority' => 'urgent',
        ])
        ->assertSessionHasNoErrors();

    CurrentOrganization::set($this->organization->id);

    $event = AuditEvent::query()->where('action', 'tasks.task.updated')->sole();

    expect($event->before['title'])->toBe('ძველი სათაური')
        ->and($event->after['title'])->toBe('ახალი სათაური')
        ->and($event->before['priority'])->toBe('normal')
        ->and($event->after['priority'])->toBe('urgent')
        // A field nobody touched is not reported as a change.
        ->and($event->before)->not->toHaveKey('planned_quantity');
});

test('a save that changes nothing writes no audit row', function () {
    $task = $this->makeTask($this->project, ['planned_quantity' => '10.00', 'status' => 'draft']);

    $payload = [
        'title' => $task->title,
        'accountable_owner_employee_id' => $task->accountable_owner_employee_id,
        'priority' => $task->priority,
        'unit' => $task->unit,
        'planned_quantity' => $task->planned_quantity,
    ];

    $this->actingAs($this->managerA)
        ->put(route('projects.tasks.update', [$this->project, $task]), $payload)
        ->assertSessionHasNoErrors();

    CurrentOrganization::set($this->organization->id);

    // A trail full of "nothing happened" entries is a trail nobody reads.
    expect(AuditEvent::query()->where('action', 'tasks.task.updated')->count())->toBe(0);
});

test('a checklist answer records who gave it, and un-ticking records that too', function () {
    $task = $this->makeTask($this->project, ['planned_quantity' => '10.00']);
    $item = ChecklistItem::query()->create([
        'organization_id' => $this->organization->id,
        'task_id' => $task->id,
        'label' => 'არმატურა შემოწმებულია',
        'is_required' => true,
    ]);

    $this->actingAs($this->performerUser)
        ->patch(route('projects.tasks.checklist-items.update', [$this->project, $task, $item]), ['is_checked' => true])
        ->assertSessionHasNoErrors();

    CurrentOrganization::set($this->organization->id);

    $checked = AuditEvent::query()->where('action', 'tasks.checklist_item.checked')->sole();

    // A checklist answer is evidence a reviewer relies on.
    expect($checked->actor_user_id)->toBe($this->performerUser->id)
        ->and($checked->after['label'])->toBe('არმატურა შემოწმებულია')
        ->and($checked->after['task_id'])->toBe($task->id);

    $this->actingAs($this->performerUser)
        ->patch(route('projects.tasks.checklist-items.update', [$this->project, $task, $item]), ['is_checked' => false])
        ->assertSessionHasNoErrors();

    CurrentOrganization::set($this->organization->id);

    expect(AuditEvent::query()->where('action', 'tasks.checklist_item.unchecked')->exists())->toBeTrue();
});

test('adding a dependency is recorded, because it decides when work may start', function () {
    $task = $this->makeTask($this->project, ['planned_quantity' => '10.00', 'status' => 'draft']);
    $upstream = $this->makeTask($this->project, ['planned_quantity' => '10.00', 'status' => 'draft']);

    $this->actingAs($this->managerA)
        ->post(route('projects.tasks.dependencies.store', [$this->project, $task]), [
            'depends_on_task_id' => $upstream->id,
        ])
        ->assertSessionHasNoErrors();

    CurrentOrganization::set($this->organization->id);

    $event = AuditEvent::query()->where('action', 'tasks.dependency.added')->sole();

    expect($event->after['depends_on_task_id'])->toBe($upstream->id)
        ->and($event->after['task_id'])->toBe($task->id);
});

test('task history reaches the project activity feed', function () {
    $task = $this->makeTask($this->project, ['planned_quantity' => '10.00', 'status' => 'draft']);

    $this->actingAs($this->managerA)
        ->post(route('projects.tasks.assign', [$this->project, $task]))
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);

    // A task belongs to exactly one project, so its history is part of that
    // project's activity — the feed gathers children by id and by the task id
    // their payload carries.
    $actions = collect(app(ProjectActivityFeed::class)->for($this->project, $this->owner))->pluck('action')->all();

    expect($actions)->toContain('tasks.task.assigned');
});

test('one project\'s task history never appears in another project\'s feed', function () {
    $otherProject = $this->makeProject('Elsewhere');
    $elsewhere = $this->makeTask($otherProject, ['planned_quantity' => '10.00', 'status' => 'draft']);

    $this->actingAs($this->managerA)
        ->post(route('projects.tasks.assign', [$otherProject, $elsewhere]))
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);

    $ids = collect(app(ProjectActivityFeed::class)->for($this->project, $this->owner))
        ->pluck('target_id')
        ->all();

    expect($ids)->not->toContain($elsewhere->id);
});
