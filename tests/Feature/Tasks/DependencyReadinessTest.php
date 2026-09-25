<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Projects\Models\Client;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Tasks\Models\TaskDependency;
use App\Domain\Tasks\Services\TaskReadiness;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Tasks\Concerns\BuildsAcceptanceFixture;

pest()->group('tasks', 'acceptance-integrity')->use(BuildsAcceptanceFixture::class);

/**
 * 03-Construction-Task-Manager-Spec-KA.md §9.1 and §9.2, acceptance row WF-01.
 *
 * Dependencies were recorded and never enforced. `task_dependencies` rows
 * existed, the cycle checker protected the graph's shape, and nothing
 * consulted the graph before letting work begin — so a task could be started
 * and finished while the work it depends on was still unaccepted.
 *
 * §9.2 is the reason that matters on site: waterproofing gets covered before
 * its inspection is accepted, and afterwards the only trace is a photo that
 * proves nothing about whether anyone approved it.
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

    $this->project = $this->makeProject('Hold Point Site');

    $this->dependOn = function ($task, $upstream) {
        TaskDependency::query()->create([
            'organization_id' => $this->organization->id,
            'task_id' => $task->id,
            'depends_on_task_id' => $upstream->id,
        ]);
    };
});

test('WF-01: work cannot start while the work it depends on is only submitted', function () {
    $inspection = $this->makeTask($this->project, ['title' => 'ჰიდროიზოლაციის შემოწმება', 'planned_quantity' => '10.00']);
    $covering = $this->makeTask($this->project, ['title' => 'დაფარვა', 'planned_quantity' => '10.00', 'status' => 'assigned']);

    ($this->dependOn)($covering, $inspection);

    // The predecessor is submitted — someone has ASKED for acceptance.
    $this->submitAsPerformer('10.00', $inspection);

    expect($inspection->refresh()->status)->toBe('submitted');

    $this->actingAs($this->performerUser)
        ->post(route('projects.tasks.start', [$this->project, $covering]))
        ->assertSessionHasErrors('status');

    CurrentOrganization::set($this->organization->id);

    // §9.1 in as many words: „წარდგენილია" is not „მიღებულია".
    expect($covering->refresh()->status)->toBe('assigned');
});

test('WF-01: once the predecessor is accepted, the work behind it may begin', function () {
    $inspection = $this->makeTask($this->project, ['title' => 'შემოწმება', 'planned_quantity' => '10.00']);
    $covering = $this->makeTask($this->project, ['title' => 'დაფარვა', 'planned_quantity' => '10.00', 'status' => 'assigned']);

    ($this->dependOn)($covering, $inspection);

    $submission = $this->submitAsPerformer('10.00', $inspection);

    $this->actingAs($this->managerA)
        ->post(route('projects.tasks.submissions.accept', [$this->project, $inspection, $submission]), [
            'accepted_quantity' => '10.00',
        ])
        ->assertSessionHasNoErrors();

    CurrentOrganization::set($this->organization->id);
    expect($inspection->refresh()->status)->toBe('completed');

    // This is the companion that keeps the test above honest: if starting were
    // broken outright, the refusal there would prove nothing.
    $this->actingAs($this->performerUser)
        ->post(route('projects.tasks.start', [$this->project, $covering]))
        ->assertSessionHasNoErrors();

    CurrentOrganization::set($this->organization->id);
    expect($covering->refresh()->status)->toBe('in_progress');
});

test('a task with no dependencies is unaffected', function () {
    $task = $this->makeTask($this->project, ['planned_quantity' => '10.00', 'status' => 'assigned']);

    $this->actingAs($this->performerUser)
        ->post(route('projects.tasks.start', [$this->project, $task]))
        ->assertSessionHasNoErrors();

    CurrentOrganization::set($this->organization->id);
    expect($task->refresh()->status)->toBe('in_progress');
});

test('a cancelled predecessor does not strand the work behind it forever', function () {
    $abandoned = $this->makeTask($this->project, ['title' => 'გაუქმებული', 'planned_quantity' => '10.00', 'status' => 'cancelled']);
    $next = $this->makeTask($this->project, ['planned_quantity' => '10.00', 'status' => 'assigned']);

    ($this->dependOn)($next, $abandoned);

    // Cancelled work is not pending work. Treating it as a permanent blocker
    // would leave every task behind it unstartable with no way out.
    $this->actingAs($this->performerUser)
        ->post(route('projects.tasks.start', [$this->project, $next]))
        ->assertSessionHasNoErrors();

    CurrentOrganization::set($this->organization->id);
    expect($next->refresh()->status)->toBe('in_progress');
});

test('the refusal names the work in the way', function () {
    $first = $this->makeTask($this->project, ['title' => 'არმატურის მიღება', 'planned_quantity' => '10.00']);
    $second = $this->makeTask($this->project, ['title' => 'ყალიბის შემოწმება', 'planned_quantity' => '10.00']);
    $blocked = $this->makeTask($this->project, ['planned_quantity' => '10.00', 'status' => 'assigned']);

    ($this->dependOn)($blocked, $first);
    ($this->dependOn)($blocked, $second);

    // „blocked by a dependency" tells a foreman standing on site nothing they
    // can act on.
    $message = app(TaskReadiness::class)->blockedMessage($blocked->refresh());

    expect($message)->toContain('არმატურის მიღება')
        ->and($message)->toContain('ყალიბის შემოწმება');
});

test('the task page says why starting is refused instead of leaving it to be discovered', function () {
    $upstream = $this->makeTask($this->project, ['title' => 'წინამორბედი', 'planned_quantity' => '10.00']);
    $blocked = $this->makeTask($this->project, ['planned_quantity' => '10.00', 'status' => 'assigned']);

    ($this->dependOn)($blocked, $upstream);

    $this->actingAs($this->performerUser)
        ->get(route('projects.tasks.show', [$this->project, $blocked]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('task.readiness.is_ready', false)
            ->where('task.readiness.blocked_by.0.title', 'წინამორბედი')
            // The button is withheld as well, so the page and the server agree
            // rather than offering an action that will be refused.
            ->where('task.can.start', false));
});

test('readiness is judged at the moment of starting, not when the page was rendered', function () {
    $upstream = $this->makeTask($this->project, ['planned_quantity' => '10.00', 'status' => 'assigned']);
    $blocked = $this->makeTask($this->project, ['planned_quantity' => '10.00', 'status' => 'assigned']);

    // The dependency is added after the performer already has the page open.
    $this->actingAs($this->performerUser)
        ->get(route('projects.tasks.show', [$this->project, $blocked]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('task.can.start', true));

    CurrentOrganization::set($this->organization->id);
    ($this->dependOn)($blocked, $upstream);

    // Readiness is a fact about other rows, which can change between rendering
    // a button and pressing it — so it is checked inside the transaction, not
    // only when the page was built.
    $this->actingAs($this->performerUser)
        ->post(route('projects.tasks.start', [$this->project, $blocked]))
        ->assertSessionHasErrors('status');

    CurrentOrganization::set($this->organization->id);
    expect($blocked->refresh()->status)->toBe('assigned');
});
