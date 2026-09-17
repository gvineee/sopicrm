<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskDependency;
use App\Domain\Tasks\Support\TaskDependencyCycleChecker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * docs/data-model.md "task_dependencies" hard rule: no cycles. Runs the
 * portable in-PHP cycle check (App\Domain\Tasks\Support\
 * TaskDependencyCycleChecker) inside the same transaction as the insert.
 */
class AddTaskDependency
{
    public function __construct(private readonly TaskDependencyCycleChecker $cycleChecker) {}

    public function execute(Task $task, string $dependsOnTaskId): TaskDependency
    {
        return DB::transaction(function () use ($task, $dependsOnTaskId) {
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

            return TaskDependency::create([
                'task_id' => $task->id,
                'depends_on_task_id' => $dependsOnTaskId,
            ]);
        });
    }
}
