<?php

namespace App\Domain\Tasks\Actions;

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

            return $task;
        });
    }
}
