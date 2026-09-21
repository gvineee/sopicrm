<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Notifications\Support\NotificationCreator;
use App\Domain\Notifications\Support\NotificationType;
use App\Domain\Notifications\Support\TaskNotificationRecipients;
use App\Domain\Tasks\Models\Comment;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskSubmission;
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
 */
class ReturnTaskSubmission
{
    public function __construct(private readonly TaskStatusEventRecorder $recorder) {}

    public function execute(TaskSubmission $submission, User $reviewer, string $reason): TaskSubmission
    {
        if ($submission->status !== 'pending_review') {
            throw ValidationException::withMessages([
                'status' => 'მხოლოდ განსახილველი (pending_review) submission-ის დაბრუნებაა შესაძლებელი.',
            ]);
        }

        $task = $submission->task;

        return DB::transaction(function () use ($submission, $reviewer, $reason, $task) {
            $submission->update(['status' => 'returned', 'returned_reason' => $reason]);

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

            return $submission->fresh();
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
