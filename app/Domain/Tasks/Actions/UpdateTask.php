<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Tasks\Models\ChecklistItem;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskAssignee;
use App\Domain\Tasks\Models\TaskDependency;
use App\Domain\Tasks\Services\TaskAuditRecorder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Editing the task's own fields (not its status — see the dedicated
 * transition Actions for that). Disallowed once the task has left the
 * active-editing part of its lifecycle (submitted/completed/cancelled),
 * matching the same "don't mutate a closed record" spirit as
 * docs/architecture.md §5's ledger-append convention, applied here to a
 * work-item rather than a financial ledger. That status gate is also what
 * keeps the checklist frozen while a reviewer is judging a submission
 * (03-Construction-Task-Manager-Spec-KA.md TM-09 / EV-04).
 *
 * Audit A08: this used to write scalar columns only, so the additional
 * performers, dependencies and checklist a person entered on the create form
 * could never be corrected afterwards by any route at all. It now manages
 * the same three collections CreateTask does.
 *
 * Each collection is applied only when the caller actually sent its key.
 * Omitting a key leaves that collection alone; sending an empty array clears
 * it. Treating "absent" as "empty" would let any partial update silently
 * disband a task's crew.
 */
class UpdateTask
{
    public function __construct(private readonly TaskAuditRecorder $audit) {}

    private const EDITABLE_STATUSES = ['draft', 'assigned', 'in_progress', 'blocked'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Task $task, array $data, ?User $actor = null): Task
    {
        if (! in_array($task->status, self::EDITABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'დავალების რედაქტირება შეუძლებელია მიმდინარე სტატუსში ('.$task->status.').',
            ]);
        }

        return DB::transaction(function () use ($task, $data, $actor) {
            $task->fill(array_intersect_key($data, array_flip([
                'title', 'description', 'project_location_id', 'work_package_id',
                'accountable_owner_employee_id', 'priority', 'due_at',
                'planned_duration_minutes', 'unit', 'planned_quantity',
                // 'self_close_allowed' is deliberately absent: TM-01 cancelled
                // it as a workflow input, and the column is history only (§17).
                'requires_photo_evidence', 'min_required_photos',
                'progress_weight', 'drawing_attachment_id', 'drawing_revision_id',
            ])));

            // Captured before the save: `getDirty()` is empty afterwards, and
            // an edit whose shape nobody can see is not a trail.
            $dirty = $task->getDirty();
            $before = array_intersect_key($task->getOriginal(), $dirty);

            $task->save();

            if ($dirty !== []) {
                $this->audit->record('tasks.task.updated', $task, $actor, before: $before, after: $dirty);
            }

            if (array_key_exists('checklist_items', $data)) {
                $this->syncChecklist($task, (array) $data['checklist_items']);
            }

            if (array_key_exists('assignee_employee_ids', $data)) {
                $this->syncAssignees($task, 'employee_id', (array) $data['assignee_employee_ids']);
            }

            if (array_key_exists('assignee_team_ids', $data)) {
                $this->syncAssignees($task, 'team_id', (array) $data['assignee_team_ids']);
            }

            if (array_key_exists('depends_on_task_ids', $data)) {
                $this->syncDependencies($task, (array) $data['depends_on_task_ids']);
            }

            return $task->fresh(['checklistItems', 'assignees', 'dependencies']);
        });
    }

    /**
     * An item carrying an id is updated in place, so a label correction keeps
     * `is_checked`, `checked_by_user_id` and `checked_at` — the record of who
     * confirmed that step and when is evidence, not formatting. Items the
     * caller no longer lists are removed.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncChecklist(Task $task, array $items): void
    {
        $existing = ChecklistItem::query()->where('task_id', $task->id)->get()->keyBy('id');
        $keptIds = [];

        foreach ($items as $item) {
            $id = isset($item['id']) && is_string($item['id']) ? $item['id'] : null;
            $label = (string) ($item['label'] ?? '');
            $isRequired = (bool) ($item['is_required'] ?? true);

            $current = $id === null ? null : $existing->get($id);

            // An id that belongs to a different task is ignored rather than
            // adopted: accepting it would let one task's edit form move
            // another task's checklist item onto itself.
            if ($current !== null) {
                $current->update(['label' => $label, 'is_required' => $isRequired]);
                $keptIds[] = $current->id;

                continue;
            }

            $keptIds[] = ChecklistItem::create([
                'task_id' => $task->id,
                'label' => $label,
                'is_required' => $isRequired,
            ])->id;
        }

        ChecklistItem::query()
            ->where('task_id', $task->id)
            ->whereNotIn('id', $keptIds === [] ? ['-'] : $keptIds)
            ->delete();
    }

    /**
     * `$ids` arrives straight from a request payload, so it is whatever the
     * client sent; narrowing it to the ids that are actually strings is this
     * method's own job rather than a promise its caller can make.
     *
     * @param  array<mixed>  $ids
     */
    private function syncAssignees(Task $task, string $column, array $ids): void
    {
        $ids = array_values(array_unique(array_filter($ids, is_string(...))));

        $existing = TaskAssignee::query()
            ->where('task_id', $task->id)
            ->whereNotNull($column)
            ->get();

        foreach ($existing as $assignee) {
            if (! in_array($assignee->{$column}, $ids, true)) {
                $assignee->delete();
            }
        }

        $currentIds = $existing->pluck($column)->all();

        foreach ($ids as $id) {
            if (! in_array($id, $currentIds, true)) {
                TaskAssignee::create(['task_id' => $task->id, $column => $id]);
            }
        }
    }

    /**
     * Additions go through AddTaskDependency so each new edge is still
     * cycle-checked inside this transaction; removals are plain deletes,
     * which can never create a cycle.
     *
     * @param  array<mixed>  $ids  Narrowed here for the same reason as in
     *                             syncAssignees(): this is raw request input.
     */
    private function syncDependencies(Task $task, array $ids): void
    {
        $ids = array_values(array_unique(array_filter(
            array_filter($ids, is_string(...)),
            // A task depending on itself is not a cycle the checker has to
            // reason about — it is simply meaningless, and rejecting it here
            // keeps the message specific.
            fn (string $id) => $id !== $task->id,
        )));

        TaskDependency::query()
            ->where('task_id', $task->id)
            ->when($ids !== [], fn ($query) => $query->whereNotIn('depends_on_task_id', $ids))
            ->delete();

        $addDependency = app(AddTaskDependency::class);

        foreach ($ids as $id) {
            $addDependency->execute($task, $id);
        }
    }
}
