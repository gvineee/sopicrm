<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Services\TaskReadiness;
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
    public function __construct(
        private readonly TaskStatusEventRecorder $recorder,
        private readonly TaskReadiness $readiness,
    ) {}

    public function execute(Task $task, User $actor): Task
    {
        if ($task->status !== 'assigned') {
            throw ValidationException::withMessages([
                'status' => 'დავალების დაწყება შესაძლებელია მხოლოდ assigned სტატუსიდან.',
            ]);
        }

        return DB::transaction(function () use ($task, $actor) {
            // §9.1: a predecessor merely „წარდგენილია" is not „მიღებულია".
            // Dependencies were recorded and never consulted, so work could
            // begin — and finish — while the work it depends on was still
            // unaccepted. §9.2's covered-work case is exactly this: the
            // waterproofing gets covered before its inspection is accepted.
            //
            // Checked inside the transaction and after the lock above, not in
            // the Policy, because readiness is a fact about other rows that can
            // change between rendering a button and pressing it.
            $blocked = $this->readiness->blockedMessage($task);

            if ($blocked !== null) {
                throw ValidationException::withMessages(['status' => $blocked]);
            }

            $from = $task->status;
            $task->update(['status' => 'in_progress']);
            $this->recorder->record($task, $from, 'in_progress', $actor);

            return $task;
        });
    }
}
