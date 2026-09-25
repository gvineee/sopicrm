<?php

namespace App\Domain\Tasks\Services;

use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskStatusEvent;
use App\Models\User;

/**
 * Spec section 10: "სრული ისტორია დარჩეს შენარჩუნებული" (full history
 * retained). The ONLY supported way to write a TaskStatusEvent row — every
 * Action in App\Domain\Tasks\Actions that changes `tasks.status` calls this
 * instead of writing the history row itself, so no transition can ever
 * silently skip leaving a trail.
 *
 * 03-Construction-Task-Manager-Spec-KA.md §16 DV-01 additionally requires a
 * real audit record per transition, with the task revision it describes. The
 * audit write happens HERE rather than in each Action for exactly the reason
 * the status row does: sixteen Actions each remembering to log is sixteen
 * chances to forget, and the Tasks domain had in fact never logged at all.
 *
 * The two records are deliberately both kept. `task_status_events` is the
 * task's own timeline, read by the task page and cheap to query per task;
 * `audit_events` is the organization-wide trail, carrying actor, request id,
 * ip and before/after, and read by the project activity feed.
 */
class TaskStatusEventRecorder
{
    public function __construct(private readonly TaskAuditRecorder $auditRecorder) {}

    public function record(Task $task, ?string $fromStatus, string $toStatus, ?User $actor, ?string $reason = null): TaskStatusEvent
    {
        $event = TaskStatusEvent::create([
            'task_id' => $task->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_user_id' => $actor?->id,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);

        $this->auditRecorder->recordTransition($task, $fromStatus, $toStatus, $actor, $reason);

        return $event;
    }
}
