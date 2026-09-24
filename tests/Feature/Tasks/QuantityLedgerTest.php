<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\Team;
use App\Domain\Projects\Models\Client;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Tasks\Models\TaskAcceptanceLedgerEntry;
use App\Domain\Tasks\Models\TaskSubmission;
use App\Domain\Tasks\Services\TaskQuantityLedger;
use Database\Seeders\AggregatingPermissionsSeeder;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Tasks\Concerns\BuildsAcceptanceFixture;

pest()->group('tasks', 'acceptance-integrity')->use(BuildsAcceptanceFixture::class);

/**
 * 03-Construction-Task-Manager-Spec-KA.md §8, acceptance rows Q-01…Q-07 and
 * SEC-04.
 *
 * The rule the whole section turns on: a submitted quantity is the volume
 * done THIS time — a delta — not a running total. §8's own worked example is
 * the specification of the arithmetic, so it is reproduced here end to end
 * rather than paraphrased into separate assertions:
 *
 *     100 m² planned → submit 40, accept 35 → net 35, task continues
 *                    → the unaccepted 5 comes back and is accepted → net 40, NOT 45
 *                    → a final 60 is accepted           → net 100, task completes
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

    $this->brigade = Team::factory()->create(['organization_id' => $this->organization->id]);

    $this->performerUser = $this->makeUser('employee');
    $this->performer = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->performerUser->id,
    ]);

    $this->project = $this->makeProject('Quantity Site');
    $this->secondProject = $this->makeProject('Second Site');

    $this->task = $this->makeTask($this->project, ['planned_quantity' => '100.00', 'unit' => 'm2']);
});

/**
 * Accepts `$quantity` of the pending submission as the independent manager
 * and puts the tenant context back, which every request tears down.
 */
function acceptAs(object $test, TaskSubmission $submission, ?string $quantity, array $extra = []): TestResponse
{
    $response = $test->actingAs($test->managerA)
        ->post(route('projects.tasks.submissions.accept', [$test->project, $test->task, $submission]), [
            'accepted_quantity' => $quantity,
            ...$extra,
        ]);

    CurrentOrganization::set($test->organization->id);

    return $response;
}

test('Q-01: accepting 35 of a submitted 40 is progress, not completion', function () {
    $submission = $this->submitAsPerformer('40.00');

    acceptAs($this, $submission, '35.00')->assertSessionHasNoErrors()->assertRedirect();

    $task = $this->task->refresh();

    expect($task->status)->toBe('in_progress')
        ->and((string) $task->accepted_quantity)->toBe('35.00')
        // §8: rejected_quantity = newly_submitted - accepted. It is derived,
        // not stored, so the two numbers it derives from must both survive.
        ->and((string) $submission->refresh()->submitted_quantity)->toBe('40.00')
        ->and((string) $submission->acceptance->accepted_quantity)->toBe('35.00');

    $entries = TaskAcceptanceLedgerEntry::query()->where('task_id', $task->id)->get();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->entry_type)->toBe(TaskAcceptanceLedgerEntry::TYPE_ACCEPTANCE)
        ->and((string) $entries[0]->quantity_delta)->toBe('35.00')
        // The 5 that was not accepted is not deducted anywhere — it was never
        // produced as far as the ledger is concerned, so it simply returns to
        // available scope: 100 - 35 = 65.
        ->and(app(TaskQuantityLedger::class)->availableScope($task))->toBe('65.00');
});

test('Q-02: re-accepting the volume that was not accepted the first time totals 40, not 45', function () {
    $first = $this->submitAsPerformer('40.00');
    acceptAs($this, $first, '35.00')->assertSessionHasNoErrors();

    // The 5 m² that did not pass, submitted again and accepted this time.
    $second = $this->submitAsPerformer('5.00');
    acceptAs($this, $second, '5.00')->assertSessionHasNoErrors();

    expect((string) $this->task->refresh()->accepted_quantity)->toBe('40.00')
        ->and($this->task->status)->toBe('in_progress');

    // And §8's closing move: the remaining 60 completes the task at exactly
    // 100, never 105.
    $third = $this->submitAsPerformer('60.00');
    acceptAs($this, $third, '60.00')->assertSessionHasNoErrors();

    $task = $this->task->refresh();

    expect((string) $task->accepted_quantity)->toBe('100.00')
        ->and($task->status)->toBe('completed')
        ->and(app(TaskQuantityLedger::class)->availableScope($task))->toBe('0.00')
        ->and(TaskAcceptanceLedgerEntry::query()->where('task_id', $task->id)->count())->toBe(3);
});

test('Q-03: rework on already-accepted volume is not new production', function () {
    $submission = $this->submitAsPerformer('40.00');
    acceptAs($this, $submission, '35.00')->assertSessionHasNoErrors();

    $ledger = app(TaskQuantityLedger::class);

    // §8: fixing a defect inside the accepted 35 m² does not produce 3 more
    // m². The ledger expresses that as a zero-delta `rework` row — the entry
    // exists so the correction is visible in the history without moving the
    // produced total.
    //
    // NOTE: no workflow writes these rows yet (there is no defect/rework task
    // entity in this codebase). This test pins the ledger semantics that such
    // a workflow must use, so it cannot later be built as a second positive
    // acceptance by accident.
    TaskAcceptanceLedgerEntry::query()->create([
        'task_id' => $this->task->id,
        'entry_type' => TaskAcceptanceLedgerEntry::TYPE_REWORK,
        'quantity_delta' => '0.00',
        'actor_user_id' => $this->managerA->id,
        'reason' => 'არმატურის ბიჯი 3 მ²-ზე გამოსასწორებელია',
        'recorded_at' => now(),
    ]);

    expect($ledger->netAccepted($this->task->refresh()))->toBe('35.00')
        ->and($ledger->availableScope($this->task))->toBe('65.00');
});

test('Q-04: a delta larger than the scope still available is refused', function () {
    $first = $this->submitAsPerformer('40.00');
    acceptAs($this, $first, '40.00')->assertSessionHasNoErrors();

    // 60 remains. Offering 70 is an overrun, and §8 forbids hiding it by
    // quietly raising the task's planned quantity — it needs a scope change.
    $this->actingAs($this->performerUser)
        ->post(route('projects.tasks.submit', [$this->project, $this->task]), ['submitted_quantity' => '70.00'])
        ->assertSessionHasErrors('submitted_quantity');

    CurrentOrganization::set($this->organization->id);

    expect(TaskSubmission::query()->where('task_id', $this->task->id)->count())->toBe(1)
        ->and((string) $this->task->refresh()->accepted_quantity)->toBe('40.00');
});

test('Q-05: replaying an acceptance with the same idempotency key makes one decision, not two', function () {
    $submission = $this->submitAsPerformer('40.00');

    acceptAs($this, $submission, '35.00', ['idempotency_key' => 'device-abc-001'])
        ->assertSessionHasNoErrors();

    // The same command arriving again — a mobile client that never saw the
    // first response and retried.
    acceptAs($this, $submission, '35.00', ['idempotency_key' => 'device-abc-001'])
        ->assertSessionHasNoErrors();

    expect(TaskAcceptanceLedgerEntry::query()->where('task_id', $this->task->id)->count())->toBe(1)
        ->and((string) $this->task->refresh()->accepted_quantity)->toBe('35.00');

    // A key already spent on another submission's decision cannot be reused
    // to reach into this one.
    $other = $this->makeTask($this->secondProject, ['planned_quantity' => '10.00']);
    $otherSubmission = $this->submitAsPerformer('10.00', $other);

    $this->actingAs($this->managerA)
        ->post(route('projects.tasks.submissions.accept', [$this->secondProject, $other, $otherSubmission]), [
            'accepted_quantity' => '10.00',
            'idempotency_key' => 'device-abc-001',
        ])
        ->assertSessionHasErrors('status');

    CurrentOrganization::set($this->organization->id);

    expect($otherSubmission->refresh()->status)->toBe('pending_review')
        ->and((string) $other->refresh()->accepted_quantity)->toBe('0.00');
});

test('Q-06: a task carries one pending submission at a time, and one decision per submission', function () {
    $submission = $this->submitAsPerformer('40.00');

    // A second submission while the first is still under review would let two
    // decisions together exceed the plan. §8 makes one pending submission per
    // task the default, and this is where it is enforced.
    $this->actingAs($this->performerUser)
        ->post(route('projects.tasks.submit', [$this->project, $this->task]), ['submitted_quantity' => '10.00'])
        ->assertSessionHasErrors('status');

    CurrentOrganization::set($this->organization->id);
    expect(TaskSubmission::query()->where('task_id', $this->task->id)->count())->toBe(1);

    acceptAs($this, $submission, '35.00')->assertSessionHasNoErrors();

    // And the decision itself happens once: the ledger's unique
    // `task_submission_id` is what makes a second decision on the same
    // submission impossible rather than merely unlikely.
    acceptAs($this, $submission, '5.00')->assertSessionHasErrors('status');

    expect(TaskAcceptanceLedgerEntry::query()->where('task_submission_id', $submission->id)->count())->toBe(1);
});

test('Q-07: zero, negative and non-numeric quantities each fail with their own reason', function () {
    // Zero submitted: §8's `0 < newly_submitted_quantity`.
    $this->actingAs($this->performerUser)
        ->post(route('projects.tasks.submit', [$this->project, $this->task]), ['submitted_quantity' => '0'])
        ->assertSessionHasErrors('submitted_quantity');

    CurrentOrganization::set($this->organization->id);

    // Negative submitted: rejected by the request rules before the Action.
    $this->actingAs($this->performerUser)
        ->post(route('projects.tasks.submit', [$this->project, $this->task]), ['submitted_quantity' => '-5'])
        ->assertSessionHasErrors('submitted_quantity');

    CurrentOrganization::set($this->organization->id);

    $this->actingAs($this->performerUser)
        ->post(route('projects.tasks.submit', [$this->project, $this->task]), ['submitted_quantity' => 'ბევრი'])
        ->assertSessionHasErrors('submitted_quantity');

    CurrentOrganization::set($this->organization->id);
    expect(TaskSubmission::query()->where('task_id', $this->task->id)->count())->toBe(0);

    $submission = $this->submitAsPerformer('40.00');

    // A zero ACCEPTANCE is not a partial acceptance of nothing — §8 says that
    // is a return, and the message has to say so, because the reviewer is
    // being told to use a different action rather than that the number is
    // malformed.
    acceptAs($this, $submission, '0')->assertSessionHasErrors('accepted_quantity');

    // Accepting more than was submitted breaks `accepted <= newly_submitted`.
    acceptAs($this, $submission, '45.00')->assertSessionHasErrors('accepted_quantity');

    expect($submission->refresh()->status)->toBe('pending_review')
        ->and(TaskAcceptanceLedgerEntry::query()->where('task_id', $this->task->id)->count())->toBe(0);
});

test('SEC-04: two decisions can never sum past the plan, even across separate submissions', function () {
    $first = $this->submitAsPerformer('60.00');
    acceptAs($this, $first, '60.00')->assertSessionHasNoErrors();

    // 40 remains. The performer offers exactly that, which is legal...
    $second = $this->submitAsPerformer('40.00');

    // ...but a reviewer accepting more than the submission carried, or more
    // than the plan still allows, is refused on the cumulative total rather
    // than on this submission alone.
    acceptAs($this, $second, '50.00')->assertSessionHasErrors('accepted_quantity');

    expect((string) $this->task->refresh()->accepted_quantity)->toBe('60.00');

    acceptAs($this, $second, '40.00')->assertSessionHasNoErrors();

    $task = $this->task->refresh();

    expect((string) $task->accepted_quantity)->toBe('100.00')
        ->and($task->status)->toBe('completed')
        ->and(app(TaskQuantityLedger::class)->availableScope($task))->toBe('0.00');
});
