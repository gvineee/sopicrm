<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Employees\Models\Employee;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskAcceptance;
use App\Domain\Tasks\Models\TaskAcceptanceLedgerEntry;
use App\Domain\Tasks\Models\TaskSubmission;
use App\Domain\Tasks\Services\ReviewerIndependence;
use App\Domain\Tasks\Services\TaskQuantityLedger;
use App\Domain\Tasks\Services\TaskStatusEventRecorder;
use App\Domain\Tasks\Support\Decimal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Task state machine: a submission under review gets a decision from an
 * independent reviewer (03-Construction-Task-Manager-Spec-KA.md §1).
 *
 * What changed against the previous implementation:
 *
 *  - TM-01/TM-02: authorization is no longer "whatever the Policy said
 *    before the request started". The independence rule is re-evaluated
 *    HERE, inside the transaction, against this specific submission's frozen
 *    participation snapshot (§13.2). A reviewer who performed the work is
 *    rejected even if the Policy was somehow satisfied — and the
 *    `self_close_allowed` carve-out no longer exists anywhere.
 *  - TM-05: acceptance stops meaning completion. Accepting 30 of 100 m² is
 *    progress; the task returns to `in_progress` and only reaches
 *    `completed` when the ledger's net accepted volume reaches the plan.
 *  - TM-06: the decision is recorded as a signed ledger delta, so
 *    re-accepting previously returned work adds the returned volume once,
 *    not twice. An acceptance of zero is refused outright: zero means the
 *    work was not accepted, which is a RETURN and must be recorded as one,
 *    with a reason the performer can read (§8).
 *  - TM-08: the submission and the task are re-read under a row lock, the
 *    caller's expected version is checked against the live one, and the
 *    ledger's unique `task_submission_id` makes the final decision on a
 *    submission a thing only one concurrent request can win.
 */
class AcceptTaskSubmission
{
    public function __construct(
        private readonly TaskStatusEventRecorder $recorder,
        private readonly TaskQuantityLedger $ledger,
        private readonly ReviewerIndependence $independence,
    ) {}

    public function execute(
        TaskSubmission $submission,
        User $acceptedBy,
        ?string $acceptedQuantity,
        ?string $notes,
        ?int $expectedVersion = null,
        ?string $idempotencyKey = null,
    ): TaskAcceptance {
        return DB::transaction(function () use ($submission, $acceptedBy, $acceptedQuantity, $notes, $expectedVersion, $idempotencyKey) {
            if ($idempotencyKey !== null) {
                $replay = TaskAcceptanceLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($replay !== null) {
                    return $this->replayOf($replay, $submission);
                }
            }

            $locked = TaskSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            $task = Task::query()->lockForUpdate()->findOrFail($locked->task_id);

            if ($expectedVersion !== null && (int) $locked->version !== $expectedVersion) {
                throw ValidationException::withMessages([
                    'status' => 'გაგზავნა შეიცვალა სხვისი მოქმედებით. გადატვირთეთ გვერდი და სცადეთ ხელახლა.',
                ]);
            }

            if ($locked->status !== 'pending_review') {
                throw ValidationException::withMessages([
                    'status' => 'მხოლოდ განსახილველი (pending_review) submission-ის მიღებაა შესაძლებელი.',
                ]);
            }

            $violation = $this->independence->violationFor($locked, $acceptedBy, $task);
            if ($violation !== null) {
                throw ValidationException::withMessages(['accepted_by' => $violation]);
            }

            $quantity = $this->resolveAcceptedQuantity($locked, $task, $acceptedQuantity);

            $acceptance = TaskAcceptance::create([
                'task_submission_id' => $locked->id,
                'accepted_by_user_id' => $acceptedBy->id,
                'accepted_quantity' => $quantity ?? 0,
                'accepted_at' => now(),
                'notes' => $notes,
            ]);

            TaskAcceptanceLedgerEntry::create([
                'task_id' => $task->id,
                'task_submission_id' => $locked->id,
                'entry_type' => TaskAcceptanceLedgerEntry::TYPE_ACCEPTANCE,
                'quantity_delta' => $quantity ?? 0,
                'actor_user_id' => $acceptedBy->id,
                'actor_employee_id' => Employee::query()->where('user_id', $acceptedBy->id)->value('id'),
                'reason' => $notes,
                'idempotency_key' => $idempotencyKey,
                'recorded_at' => now(),
            ]);

            $locked->update(['status' => 'accepted']);

            $netAccepted = $this->ledger->netAccepted($task);
            $completes = $this->ledger->completesTask($task, $netAccepted);
            $to = $completes ? 'completed' : 'in_progress';

            $from = $task->status;
            $task->update([
                'accepted_quantity' => $netAccepted,
                'status' => $to,
            ]);
            $this->recorder->record($task, $from, $to, $acceptedBy, $notes);

            $submission->setRawAttributes($locked->getAttributes(), true);

            return $acceptance;
        });
    }

    /**
     * §8: `0 <= accepted_quantity <= newly_submitted_quantity`, with zero
     * carved out as a return rather than an acceptance, and the net total
     * still bounded by the task's baseline.
     */
    private function resolveAcceptedQuantity(TaskSubmission $submission, Task $task, ?string $acceptedQuantity): ?string
    {
        if ($submission->submitted_quantity === null) {
            if ($acceptedQuantity !== null) {
                throw ValidationException::withMessages([
                    'accepted_quantity' => 'ამ submission-ს არ აქვს მოცულობა — accepted_quantity ცარიელი უნდა იყოს.',
                ]);
            }

            return null;
        }

        if ($acceptedQuantity === null || ! is_numeric($acceptedQuantity)) {
            throw ValidationException::withMessages([
                'accepted_quantity' => 'მიუთითეთ მიღებული მოცულობა.',
            ]);
        }

        $quantity = Decimal::of($acceptedQuantity);

        if (bccomp($quantity, '0', 2) !== 1) {
            throw ValidationException::withMessages([
                'accepted_quantity' => 'ნულოვანი მიღება არ არსებობს — თუ სამუშაო არ მიიღება, გამოიყენეთ „დაბრუნება" მიზეზის მითითებით.',
            ]);
        }

        if (bccomp($quantity, Decimal::of($submission->submitted_quantity), 2) === 1) {
            throw ValidationException::withMessages([
                'accepted_quantity' => 'მიღებული მოცულობა ვერ აღემატება გაგზავნილ მოცულობას.',
            ]);
        }

        if ($task->planned_quantity !== null) {
            $netAfter = bcadd(Decimal::of($this->ledger->netAccepted($task)), $quantity, 2);

            if (bccomp($netAfter, Decimal::of($task->planned_quantity), 2) === 1) {
                throw ValidationException::withMessages([
                    'accepted_quantity' => 'ჯამური მიღებული მოცულობა ვერ აღემატება დაგეგმილ მოცულობას.',
                ]);
            }
        }

        return $quantity;
    }

    /**
     * A replayed command (same idempotency key) must resolve to the decision
     * it already made, never to a second one — and never to another
     * submission's decision, which is what a blindly-trusted client key
     * would otherwise allow.
     */
    private function replayOf(TaskAcceptanceLedgerEntry $entry, TaskSubmission $submission): TaskAcceptance
    {
        $acceptance = $entry->task_submission_id === $submission->id
            ? TaskAcceptance::query()->where('task_submission_id', $submission->id)->first()
            : null;

        if ($acceptance === null) {
            throw ValidationException::withMessages([
                'status' => 'ეს idempotency key უკვე გამოყენებულია სხვა გადაწყვეტილებისთვის.',
            ]);
        }

        return $acceptance;
    }
}
