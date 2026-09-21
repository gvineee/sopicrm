<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Notifications\Support\NotificationCreator;
use App\Domain\Notifications\Support\NotificationType;
use App\Domain\Notifications\Support\TaskNotificationRecipients;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Services\TaskStatusEventRecorder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Task state machine (spec section 10): draft -> assigned.
 */
class AssignTask
{
    public function __construct(private readonly TaskStatusEventRecorder $recorder) {}

    public function execute(Task $task, User $actor): Task
    {
        if ($task->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => 'დავალების მინიჭება შესაძლებელია მხოლოდ draft სტატუსიდან.',
            ]);
        }

        return DB::transaction(function () use ($task, $actor) {
            $from = $task->status;
            $task->update(['status' => 'assigned']);
            $this->recorder->record($task, $from, 'assigned', $actor);
            $this->notifyPerformers($task);

            return $task;
        });
    }

    /**
     * NOTIFY-01: notifies every currently-assigned performer's own User
     * account (individual or whole team) — see
     * App\Domain\Notifications\Support\TaskNotificationRecipients for how
     * `task_assignees` rows resolve to real recipients.
     */
    private function notifyPerformers(Task $task): void
    {
        foreach (TaskNotificationRecipients::forTask($task) as $recipient) {
            NotificationCreator::create(
                recipient: $recipient,
                type: NotificationType::TASK_ASSIGNED,
                title: 'ახალი დავალება',
                message: "თქვენ დაგენიშნათ დავალება: {$task->title}",
                dedupKey: "task_assigned:{$task->id}:{$recipient->id}",
                deepLink: "/projects/{$task->project_id}/tasks/{$task->id}",
            );
        }
    }
}
