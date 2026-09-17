<?php

namespace App\Domain\Tasks\Support;

use App\Domain\Tasks\Models\TaskDependency;

/**
 * docs/data-model.md "task_dependencies" hard rule: no dependency cycles.
 * Postgres has no native constraint for an arbitrary-depth DAG cycle, and a
 * `WITH RECURSIVE` check would only run on Postgres — this app's Pest suite
 * runs against sqlite (phpunit.xml), so cycle detection is implemented as a
 * plain, portable in-PHP graph traversal instead, run inside the same DB
 * transaction as the insert (see
 * App\Domain\Tasks\Actions\AddTaskDependency) so a concurrent insert can't
 * race past it.
 */
class TaskDependencyCycleChecker
{
    /**
     * True if adding "taskId depends_on dependsOnTaskId" would create a
     * cycle — i.e. dependsOnTaskId can already (transitively) reach taskId
     * via existing depends_on edges, or they're the same task.
     */
    public function wouldCreateCycle(string $taskId, string $dependsOnTaskId): bool
    {
        if ($taskId === $dependsOnTaskId) {
            return true;
        }

        $visited = [];
        $queue = [$dependsOnTaskId];

        while ($queue !== []) {
            $current = array_shift($queue);

            if ($current === $taskId) {
                return true;
            }

            if (isset($visited[$current])) {
                continue;
            }

            $visited[$current] = true;

            $next = TaskDependency::query()
                ->where('task_id', $current)
                ->pluck('depends_on_task_id')
                ->all();

            array_push($queue, ...$next);
        }

        return false;
    }
}
