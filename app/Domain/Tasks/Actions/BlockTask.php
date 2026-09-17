<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Services\TaskStatusEventRecorder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Task state machine (spec section 10): [assigned, in_progress] -> blocked,
 * "ჰქონდეს მიზეზი და მისი მოხსნის პასუხისმგებელი" (has a reason and an
 * owner of unblocking it) — both required, not optional.
 */
class BlockTask
{
    private const BLOCKABLE_FROM = ['assigned', 'in_progress'];

    public function __construct(private readonly TaskStatusEventRecorder $recorder) {}

    public function execute(Task $task, string $reason, string $blockedOwnerEmployeeId, User $actor): Task
    {
        if (! in_array($task->status, self::BLOCKABLE_FROM, true)) {
            throw ValidationException::withMessages([
                'status' => 'დავალების დაბლოკვა შესაძლებელია მხოლოდ assigned/in_progress სტატუსიდან.',
            ]);
        }

        return DB::transaction(function () use ($task, $reason, $blockedOwnerEmployeeId, $actor) {
            $from = $task->status;
            $task->update([
                'status' => 'blocked',
                'blocked_reason' => $reason,
                'blocked_owner_employee_id' => $blockedOwnerEmployeeId,
            ]);
            $this->recorder->record($task, $from, 'blocked', $actor, $reason);

            return $task;
        });
    }
}
