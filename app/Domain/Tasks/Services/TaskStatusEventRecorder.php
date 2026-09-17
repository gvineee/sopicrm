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
 */
class TaskStatusEventRecorder
{
    public function record(Task $task, ?string $fromStatus, string $toStatus, ?User $actor, ?string $reason = null): TaskStatusEvent
    {
        return TaskStatusEvent::create([
            'task_id' => $task->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_user_id' => $actor?->id,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }
}
