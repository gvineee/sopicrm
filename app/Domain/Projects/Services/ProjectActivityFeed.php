<?php

namespace App\Domain\Projects\Services;

use App\Domain\Auth\Models\ProjectMembership;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectLocation;
use App\Domain\Projects\Models\WorkPackage;
use App\Domain\Shared\Models\AuditEvent;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Audit A06: the project's „აქტივობა" tab said out loud that a project
 * activity journal was not implemented. The write side already existed —
 * fifteen project-domain actions have been recording actor, time, reason and
 * before/after into `audit_events` all along — but nothing ever read it, in
 * any screen, by any route.
 *
 * Two things make this a query service rather than a one-line `where`:
 *
 * 1. `audit_events` has no `project_id`. Events are addressed by
 *    `target_type`/`target_id`, and most of what happens "in a project"
 *    targets a CHILD row — a membership, a WBS location, a work package, an
 *    uploaded document. So the feed is the union of the project's own events
 *    and the events of each child kind, gathered by id.
 *
 *    A consequence worth stating plainly: a child that was hard-deleted can
 *    no longer be found by id, so its own creation/update events drop out of
 *    this feed even though the deletion event (which targets the child and
 *    carries its `before` payload) is still there and still found, because
 *    the delete actions record the id being deleted. Nothing is fabricated to
 *    paper over that; a denormalised `project_id` on `audit_events` is what
 *    would fix it properly, and that is a migration this does not make.
 *
 * 2. `before`/`after` are stored whole. `CreateProjectAction` logs the
 *    project's entire attribute set, which includes `budget_baseline` — a
 *    field ProjectPolicy gates separately behind `viewBudget`. Showing the
 *    journal without redacting it would hand every project member the budget
 *    through the back door. AuditLogger's own write-time masking does not
 *    cover this, so the read side has to.
 */
class ProjectActivityFeed
{
    /** Fields a viewer may only see if they may see that field on the record itself. */
    private const BUDGET_FIELDS = ['budget_baseline'];

    /**
     * @return list<array<string, mixed>>
     */
    public function for(Project $project, User $viewer, int $limit = 100): array
    {
        $events = $this->eventsFor($project, $limit);
        $canSeeBudget = $viewer->can('viewBudget', $project);

        return array_values($events
            ->map(fn (AuditEvent $event) => [
                'id' => $event->id,
                'action' => $event->action,
                'target_type' => class_basename((string) $event->target_type),
                'target_id' => $event->target_id,
                'reason' => $event->reason,
                'occurred_at' => $event->created_at?->toIso8601String(),
                // `??` already isolates the whole left-hand expression, so a
                // missing actor falls through to the label the logger captured
                // at write time rather than blowing up.
                'actor_name' => $event->actor->name ?? $event->actor_label,
                'before' => $this->redact($event->before, $canSeeBudget),
                'after' => $this->redact($event->after, $canSeeBudget),
            ])
            ->all());
    }

    /**
     * @return Collection<int, AuditEvent>
     */
    private function eventsFor(Project $project, int $limit): Collection
    {
        $membershipIds = ProjectMembership::query()->where('project_id', $project->id)->pluck('id');
        $locationIds = ProjectLocation::query()->where('project_id', $project->id)->pluck('id');
        $workPackageIds = WorkPackage::query()->where('project_id', $project->id)->pluck('id');
        // The Tasks domain now writes audit events (DV-01), and a task is a
        // child of exactly one project, so its history belongs in the
        // project's activity too.
        $taskIds = Task::query()->where('project_id', $project->id)->pluck('id');

        return AuditEvent::query()
            ->with('actor:id,name')
            ->where(function ($query) use ($project, $membershipIds, $locationIds, $workPackageIds, $taskIds): void {
                $query->where(function ($own) use ($project): void {
                    $own->where('target_type', $project->getMorphClass())
                        ->where('target_id', $project->id);
                });

                foreach ([
                    [ProjectMembership::class, $membershipIds],
                    [ProjectLocation::class, $locationIds],
                    [WorkPackage::class, $workPackageIds],
                    [Task::class, $taskIds],
                ] as [$class, $ids]) {
                    if ($ids->isEmpty()) {
                        continue;
                    }

                    $query->orWhere(function ($child) use ($class, $ids): void {
                        $child->where('target_type', (new $class)->getMorphClass())
                            ->whereIn('target_id', $ids);
                    });
                }

                // Task children — a dependency, a checklist answer — target
                // their own row, so they are found by the task id their
                // payload carries rather than by a join.
                if ($taskIds->isNotEmpty()) {
                    $query->orWhere(function ($taskChildren) use ($taskIds): void {
                        $taskChildren->where('action', 'like', 'tasks.%')
                            ->whereIn('after->task_id', $taskIds);
                    });
                }

                // Documents are attachments owned by the project, and the
                // upload/delete actions record that ownership in the payload
                // rather than in a column we could join on. Matching the
                // action prefix plus this project's id inside the payload is
                // the honest available filter.
                $query->orWhere(function ($documents) use ($project): void {
                    $documents->whereIn('action', ['projects.document.uploaded', 'projects.document.deleted'])
                        ->where(function ($payload) use ($project): void {
                            $payload->where('after->owner_id', $project->id)
                                ->orWhere('before->owner_id', $project->id);
                        });
                });
            })
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private function redact(?array $payload, bool $canSeeBudget): ?array
    {
        if ($payload === null || $canSeeBudget) {
            return $payload;
        }

        foreach (self::BUDGET_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                // Replaced rather than removed: a viewer should be able to see
                // THAT the budget changed and who changed it, without being
                // shown the figure they are not cleared for.
                $payload[$field] = '***';
            }
        }

        return $payload;
    }
}
