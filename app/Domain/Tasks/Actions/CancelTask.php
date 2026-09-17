<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Services\TaskStatusEventRecorder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Task state machine (spec section 10): cancel requires a reason. Allowed
 * from any non-terminal status; a `completed` task is reopened, not
 * cancelled (see ReopenTask), and an already-`cancelled` task cannot be
 * cancelled again.
 */
class CancelTask
{
    private const NOT_CANCELLABLE_FROM = ['cancelled', 'completed'];

    public function __construct(private readonly TaskStatusEventRecorder $recorder) {}

    public function execute(Task $task, string $reason, User $actor): Task
    {
        if (in_array($task->status, self::NOT_CANCELLABLE_FROM, true)) {
            throw ValidationException::withMessages([
                'status' => 'ამ სტატუსში მყოფი დავალების გაუქმება შეუძლებელია.',
            ]);
        }

        return DB::transaction(function () use ($task, $reason, $actor) {
            $from = $task->status;
            $task->update(['status' => 'cancelled', 'cancelled_reason' => $reason]);
            $this->recorder->record($task, $from, 'cancelled', $actor, $reason);

            return $task;
        });
    }
}
