<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskDependency;
use App\Domain\Tasks\Services\TaskAuditRecorder;
use App\Domain\Tasks\Support\TaskDependencyCycleChecker;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * docs/data-model.md "task_dependencies" hard rule: no cycles. Runs the
 * portable in-PHP cycle check (App\Domain\Tasks\Support\
 * TaskDependencyCycleChecker) inside the same transaction as the insert.
 */
class AddTaskDependency
{
    public function __construct(
        private readonly TaskDependencyCycleChecker $cycleChecker,
        private readonly TaskAuditRecorder $audit,
    ) {}

    public function execute(Task $task, string $dependsOnTaskId, ?User $actor = null): TaskDependency
    {
        return DB::transaction(function () use ($task, $dependsOnTaskId, $actor) {
            $existing = TaskDependency::query()
                ->where('task_id', $task->id)
                ->where('depends_on_task_id', $dependsOnTaskId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            if ($this->cycleChecker->wouldCreateCycle($task->id, $dependsOnTaskId)) {
                throw ValidationException::withMessages([
                    'depends_on_task_id' => 'ეს დამოკიდებულება ციკლს შექმნიდა დავალებებს შორის — შეუძლებელია.',
                ]);
            }

            $dependency = TaskDependency::create([
                'task_id' => $task->id,
                'depends_on_task_id' => $dependsOnTaskId,
            ]);

            // A dependency decides when work may start, so who added one and
            // when belongs in the task's history rather than nowhere.
            $this->audit->record(
                'tasks.dependency.added',
                $task,
                $actor,
                after: ['depends_on_task_id' => $dependsOnTaskId],
                target: $dependency,
            );

            return $dependency;
        });
    }
}
