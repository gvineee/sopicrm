<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Services\TaskStatusEventRecorder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Task state machine (spec section 10): blocked -> in_progress.
 */
class UnblockTask
{
    public function __construct(private readonly TaskStatusEventRecorder $recorder) {}

    public function execute(Task $task, User $actor, ?string $note = null): Task
    {
        if ($task->status !== 'blocked') {
            throw ValidationException::withMessages([
                'status' => 'დავალების განბლოკვა შესაძლებელია მხოლოდ blocked სტატუსიდან.',
            ]);
        }

        return DB::transaction(function () use ($task, $actor, $note) {
            $from = $task->status;
            $task->update([
                'status' => 'in_progress',
                'blocked_reason' => null,
                'blocked_owner_employee_id' => null,
            ]);
            $this->recorder->record($task, $from, 'in_progress', $actor, $note);

            return $task;
        });
    }
}
