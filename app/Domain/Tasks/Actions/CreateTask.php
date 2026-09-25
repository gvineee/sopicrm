<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Tasks\Models\ChecklistItem;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskAssignee;
use App\Domain\Tasks\Services\TaskStatusEventRecorder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * spec section 10 task form: title, description, project/location, exactly
 * one accountable owner, optional additional assignees or a brigade,
 * priority, due date, planned duration, checklist, dependencies (added
 * separately via AddTaskDependency so cycle-checking happens per edge),
 * unit/planned quantity. Always created in `draft` (tasks.status DB
 * default) — see AssignTask for the draft->assigned transition.
 */
class CreateTask
{
    public function __construct(private readonly TaskStatusEventRecorder $recorder) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data, User $actor): Task
    {
        return DB::transaction(function () use ($data, $actor) {
            $task = Task::create([
                // Explicit rather than relying on the DB column default:
                // Eloquent does not re-fetch an unfilled default column after
                // INSERT, so `$task->status` below would otherwise read as
                // null in-memory even though the row itself defaults to
                // 'draft' — this is exactly that value, made real.
                'status' => 'draft',
                'project_id' => $data['project_id'],
                'project_location_id' => $data['project_location_id'] ?? null,
                'work_package_id' => $data['work_package_id'] ?? null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'accountable_owner_employee_id' => $data['accountable_owner_employee_id'],
                'priority' => $data['priority'] ?? 'normal',
                'due_at' => $data['due_at'] ?? null,
                'planned_duration_minutes' => $data['planned_duration_minutes'] ?? null,
                'unit' => $data['unit'] ?? null,
                'planned_quantity' => $data['planned_quantity'] ?? null,
                // TM-01: always false. The column is kept for history (§17);
                // no new task is ever created with the cancelled carve-out on.
                'self_close_allowed' => false,
                'requires_photo_evidence' => $data['requires_photo_evidence'] ?? true,
                'min_required_photos' => $data['min_required_photos'] ?? 1,
                'progress_weight' => $data['progress_weight'] ?? null,
                'drawing_attachment_id' => $data['drawing_attachment_id'] ?? null,
                'drawing_revision_id' => $data['drawing_revision_id'] ?? null,
            ]);

            foreach ($data['checklist_items'] ?? [] as $item) {
                ChecklistItem::create([
                    'task_id' => $task->id,
                    'label' => $item['label'],
                    'is_required' => $item['is_required'] ?? true,
                ]);
            }

            foreach ($data['assignee_employee_ids'] ?? [] as $employeeId) {
                TaskAssignee::create(['task_id' => $task->id, 'employee_id' => $employeeId]);
            }

            foreach ($data['assignee_team_ids'] ?? [] as $teamId) {
                TaskAssignee::create(['task_id' => $task->id, 'team_id' => $teamId]);
            }

            $addDependency = app(AddTaskDependency::class);
            foreach ($data['depends_on_task_ids'] ?? [] as $dependsOnTaskId) {
                $addDependency->execute($task, $dependsOnTaskId);
            }

            $this->recorder->record($task, null, $task->status, $actor);

            return $task->fresh(['checklistItems', 'assignees', 'dependencies']);
        });
    }
}
