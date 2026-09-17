<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Services\TaskStatusEventRecorder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Task state machine (spec section 10): reopen requires a reason. Only
 * `completed`/`cancelled` (terminal states) can be reopened, and reopening
 * always resumes at `in_progress` — a documented simplification (see
 * docs/decisions.md) rather than trying to reconstruct whatever
 * intermediate state preceded the terminal one from history.
 *
 * Reopening a completed task deliberately does NOT reset
 * `tasks.accepted_quantity` — the previously accepted volume is real work
 * that happened; a manager reopening the task to request more/corrected
 * work adds to it via a new submission/acceptance cycle, it does not erase
 * the record of what was already accepted.
 */
class ReopenTask
{
    private const REOPENABLE_FROM = ['completed', 'cancelled'];

    public function __construct(private readonly TaskStatusEventRecorder $recorder) {}

    public function execute(Task $task, string $reason, User $actor): Task
    {
        if (! in_array($task->status, self::REOPENABLE_FROM, true)) {
            throw ValidationException::withMessages([
                'status' => 'დავალების ხელახლა გახსნა შესაძლებელია მხოლოდ completed/cancelled სტატუსიდან.',
            ]);
        }

        return DB::transaction(function () use ($task, $reason, $actor) {
            $from = $task->status;
            $task->update([
                'status' => 'in_progress',
                'reopened_reason' => $reason,
                'cancelled_reason' => null,
            ]);
            $this->recorder->record($task, $from, 'in_progress', $actor, $reason);

            return $task;
        });
    }
}
