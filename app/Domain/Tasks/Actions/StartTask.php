<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Services\TaskStatusEventRecorder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Task state machine (spec section 10): assigned -> in_progress (the
 * performer starting work — spec 4's mobile "მუშაობის დაწყება" action).
 */
class StartTask
{
    public function __construct(private readonly TaskStatusEventRecorder $recorder) {}

    public function execute(Task $task, User $actor): Task
    {
        if ($task->status !== 'assigned') {
            throw ValidationException::withMessages([
                'status' => 'დავალების დაწყება შესაძლებელია მხოლოდ assigned სტატუსიდან.',
            ]);
        }

        return DB::transaction(function () use ($task, $actor) {
            $from = $task->status;
            $task->update(['status' => 'in_progress']);
            $this->recorder->record($task, $from, 'in_progress', $actor);

            return $task;
        });
    }
}
