<?php

namespace App\Domain\Tasks\Services;

use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskDependency;
use Illuminate\Support\Collection;

/**
 * 03-Construction-Task-Manager-Spec-KA.md §9.1: „სამუშაოს დაწყებამდე
 * დავალებამ შეამოწმოს წინამორბედის საჭირო მდგომარეობა … წინამორბედის
 * უბრალოდ „წარდგენილია" არ უდრის „მიღებულია"."
 *
 * Dependencies were recorded and never enforced. `task_dependencies` rows
 * existed, the cycle checker protected the graph's shape, and nothing
 * anywhere consulted the graph before letting work begin — so a task could be
 * started and finished while the work it depends on was still unaccepted, or
 * still a draft nobody had begun.
 *
 * That is §9.2's covered-work problem in its plain form: waterproofing gets
 * covered before its inspection is accepted, and the only trace afterwards is
 * a photo that proves nothing about whether anyone approved it.
 *
 * The rule this class enforces is exactly the one the spec states and no more:
 * a predecessor counts as satisfied only when it is `completed`, which in this
 * codebase means its submitted volume was accepted by an independent reviewer
 * (TM-01). `submitted` is explicitly not enough — it means someone has ASKED
 * for acceptance, not received it. A `cancelled` predecessor is also satisfied:
 * cancelled work is not pending work, and treating it as a permanent blocker
 * would strand every task behind it.
 *
 * It deliberately invents no engineering criteria. Which steps need a hold
 * point is a decision for the organization's own specialists, expressed by
 * creating the dependency; this class only refuses to ignore one that exists.
 */
class TaskReadiness
{
    /** A predecessor in one of these states no longer blocks the work behind it. */
    private const SATISFIED_STATUSES = ['completed', 'cancelled'];

    /**
     * The predecessors standing in this task's way, in the order they were
     * added. Empty means the work may begin.
     *
     * @return Collection<int, Task>
     */
    public function blockingDependencies(Task $task): Collection
    {
        $dependsOnIds = TaskDependency::query()
            ->where('task_id', $task->id)
            ->pluck('depends_on_task_id');

        if ($dependsOnIds->isEmpty()) {
            return collect();
        }

        return Task::query()
            ->whereIn('id', $dependsOnIds)
            ->whereNotIn('status', self::SATISFIED_STATUSES)
            ->orderBy('title')
            ->get();
    }

    public function isReady(Task $task): bool
    {
        return $this->blockingDependencies($task)->isEmpty();
    }

    /**
     * A message that names the work in the way, because "blocked by a
     * dependency" tells a foreman standing on site nothing they can act on.
     */
    public function blockedMessage(Task $task): ?string
    {
        $blocking = $this->blockingDependencies($task);

        if ($blocking->isEmpty()) {
            return null;
        }

        $names = $blocking
            ->map(fn (Task $dependency) => '„'.$dependency->title.'"')
            ->implode(', ');

        return $blocking->count() === 1
            ? "დაწყება შეუძლებელია: ჯერ უნდა დასრულდეს და მიღებულ იქნას {$names}."
            : "დაწყება შეუძლებელია: ჯერ უნდა დასრულდეს და მიღებულ იქნას შემდეგი დავალებები — {$names}.";
    }

    /**
     * The shape the task page renders so a person can see WHY the start button
     * is refused, rather than finding out by pressing it.
     *
     * @return array<string, mixed>
     */
    public function summaryFor(Task $task): array
    {
        $blocking = $this->blockingDependencies($task);

        return [
            'is_ready' => $blocking->isEmpty(),
            'blocked_by' => $blocking->map(fn (Task $dependency) => [
                'id' => $dependency->id,
                'project_id' => $dependency->project_id,
                'title' => $dependency->title,
                'status' => $dependency->status,
            ])->values()->all(),
        ];
    }
}
