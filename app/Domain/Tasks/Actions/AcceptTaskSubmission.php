<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Tasks\Models\TaskAcceptance;
use App\Domain\Tasks\Models\TaskSubmission;
use App\Domain\Tasks\Services\TaskStatusEventRecorder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Task state machine (spec section 10): submitted -> completed. Manager (or
 * the same employee, ONLY when the task's pre-enabled, audited
 * `self_close_allowed` policy applies — see App\Policies\TaskPolicy::
 * acceptSubmission, which is where that authorization actually lives, not
 * here) accepts the submitted work.
 *
 * Hard rules (docs/data-model.md "task_acceptances"):
 *  - accepted_quantity <= that submission's submitted_quantity.
 *  - Re-acceptance never double-counts: `task_acceptances.task_submission_id`
 *    is DB-unique, so a second accept on the same submission fails at the
 *    DB layer; `tasks.accepted_quantity` is always RECOMPUTED as the sum of
 *    every accepted submission's accepted_quantity for this task, never
 *    incremented with `+=`, so it can never drift from that sum regardless
 *    of how many times this Action runs.
 */
class AcceptTaskSubmission
{
    public function __construct(private readonly TaskStatusEventRecorder $recorder) {}

    public function execute(TaskSubmission $submission, User $acceptedBy, ?string $acceptedQuantity, ?string $notes): TaskAcceptance
    {
        if ($submission->status !== 'pending_review') {
            throw ValidationException::withMessages([
                'status' => 'მხოლოდ განსახილველი (pending_review) submission-ის მიღებაა შესაძლებელი.',
            ]);
        }

        if ($submission->acceptance()->exists()) {
            throw ValidationException::withMessages([
                'status' => 'ეს submission უკვე მიღებულია.',
            ]);
        }

        if ($submission->submitted_quantity === null) {
            if ($acceptedQuantity !== null) {
                throw ValidationException::withMessages([
                    'accepted_quantity' => 'ამ submission-ს არ აქვს მოცულობა — accepted_quantity ცარიელი უნდა იყოს.',
                ]);
            }
        } elseif ($acceptedQuantity === null || ! is_numeric($acceptedQuantity) || bccomp($acceptedQuantity, $submission->submitted_quantity, 2) === 1) {
            throw ValidationException::withMessages([
                'accepted_quantity' => 'მიღებული მოცულობა ვერ აღემატება გაგზავნილ მოცულობას.',
            ]);
        }

        $task = $submission->task;

        return DB::transaction(function () use ($submission, $acceptedBy, $acceptedQuantity, $notes, $task) {
            $acceptance = TaskAcceptance::create([
                'task_submission_id' => $submission->id,
                'accepted_by_user_id' => $acceptedBy->id,
                'accepted_quantity' => $acceptedQuantity ?? 0,
                'accepted_at' => now(),
                'notes' => $notes,
            ]);

            $submission->update(['status' => 'accepted']);

            // Recompute from the source of truth (sum of every accepted
            // submission for this task) — never `+=` — so repeated runs or
            // re-review can never double-count (spec hard rule).
            $totalAccepted = TaskAcceptance::query()
                ->join('task_submissions', 'task_submissions.id', '=', 'task_acceptances.task_submission_id')
                ->where('task_submissions.task_id', $task->id)
                ->sum('task_acceptances.accepted_quantity');

            $from = $task->status;
            $task->update([
                'accepted_quantity' => $totalAccepted,
                'status' => 'completed',
            ]);
            $this->recorder->record($task, $from, 'completed', $acceptedBy, $notes);

            return $acceptance;
        });
    }
}
