<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Employees\Models\Employee;
use App\Domain\Notifications\Support\NotificationCreator;
use App\Domain\Notifications\Support\NotificationType;
use App\Domain\Notifications\Support\TaskNotificationRecipients;
use App\Domain\Tasks\Models\Comment;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskAcceptanceLedgerEntry;
use App\Domain\Tasks\Models\TaskSubmission;
use App\Domain\Tasks\Services\ReviewerIndependence;
use App\Domain\Tasks\Services\TaskStatusEventRecorder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Task state machine (spec section 10): submitted -> in_progress, "reviewer
 * return-to-in_progress-with-comment" — the reviewer's reason is required
 * and is recorded both on the submission (`returned_reason`) and as a real
 * Comment on the task (so it shows up in the same comment thread the
 * employee already reads, not only in a status-history log they'd have to
 * go looking for).
 *
 * 03-Construction-Task-Manager-Spec-KA.md §8 makes the return the other half
 * of one decision, not a lesser action: it gets the same reviewer-
 * independence re-check inside the transaction as an acceptance (§13.2), and
 * it writes a zero-delta ledger entry. That entry's unique
 * `task_submission_id` is what makes a concurrent accept and return on the
 * same submission resolve to exactly one outcome instead of both landing.
 * The returned volume is not deducted anywhere — it was never accepted, so
 * it simply becomes available to submit again, which is why §8's worked
 * example totals 40 and not 45.
 */
class ReturnTaskSubmission
{
    public function __construct(
        private readonly TaskStatusEventRecorder $recorder,
        private readonly ReviewerIndependence $independence,
    ) {}

    public function execute(
        TaskSubmission $submission,
        User $reviewer,
        string $reason,
        ?int $expectedVersion = null,
        ?string $idempotencyKey = null,
    ): TaskSubmission {
        return DB::transaction(function () use ($submission, $reviewer, $reason, $expectedVersion, $idempotencyKey) {
            if ($idempotencyKey !== null) {
                $replay = TaskAcceptanceLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($replay !== null) {
                    if ($replay->task_submission_id !== $submission->id) {
                        throw ValidationException::withMessages([
                            'status' => 'ეს idempotency key უკვე გამოყენებულია სხვა გადაწყვეტილებისთვის.',
                        ]);
                    }

                    return $submission->fresh();
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
                    'status' => 'მხოლოდ განსახილველი (pending_review) submission-ის დაბრუნებაა შესაძლებელი.',
                ]);
            }

            $violation = $this->independence->violationFor($locked, $reviewer, $task);
            if ($violation !== null) {
                throw ValidationException::withMessages(['reason' => $violation]);
            }

            $locked->update(['status' => 'returned', 'returned_reason' => $reason]);

            TaskAcceptanceLedgerEntry::create([
                'task_id' => $task->id,
                'task_submission_id' => $locked->id,
                'entry_type' => TaskAcceptanceLedgerEntry::TYPE_RETURN,
                'quantity_delta' => 0,
                'actor_user_id' => $reviewer->id,
                'actor_employee_id' => Employee::query()->where('user_id', $reviewer->id)->value('id'),
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'recorded_at' => now(),
            ]);

            $from = $task->status;
            $task->update(['status' => 'in_progress']);
            $this->recorder->record($task, $from, 'in_progress', $reviewer, $reason);

            Comment::create([
                'commentable_type' => $task->getMorphClass(),
                'commentable_id' => $task->id,
                'author_user_id' => $reviewer->id,
                'body' => $reason,
                'mentions' => [],
            ]);

            $this->notifyPerformers($task);

            $submission->setRawAttributes($locked->getAttributes(), true);

            return $locked->fresh();
        });
    }

    /**
     * NOTIFY-01: dedup key includes the submission id (not just the task
     * id) — a task can legitimately be returned more than once across its
     * lifetime, and each real return is its own notification.
     */
    private function notifyPerformers(Task $task): void
    {
        foreach (TaskNotificationRecipients::forTask($task) as $recipient) {
            NotificationCreator::create(
                recipient: $recipient,
                type: NotificationType::TASK_RETURNED,
                title: 'დავალება დაბრუნდა',
                message: "დავალება \"{$task->title}\" დაბრუნდა შესასწორებლად",
                dedupKey: "task_returned:{$task->id}:{$task->updated_at?->timestamp}",
                deepLink: "/projects/{$task->project_id}/tasks/{$task->id}",
            );
        }
    }
}
