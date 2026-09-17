<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Tasks\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Editing the task's own fields (not its status — see the dedicated
 * transition Actions for that). Disallowed once the task has left the
 * active-editing part of its lifecycle (submitted/completed/cancelled),
 * matching the same "don't mutate a closed record" spirit as
 * docs/architecture.md §5's ledger-append convention, applied here to a
 * work-item rather than a financial ledger.
 */
class UpdateTask
{
    private const EDITABLE_STATUSES = ['draft', 'assigned', 'in_progress', 'blocked'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Task $task, array $data): Task
    {
        if (! in_array($task->status, self::EDITABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'დავალების რედაქტირება შეუძლებელია მიმდინარე სტატუსში ('.$task->status.').',
            ]);
        }

        return DB::transaction(function () use ($task, $data) {
            $task->fill(array_intersect_key($data, array_flip([
                'title', 'description', 'project_location_id', 'work_package_id',
                'accountable_owner_employee_id', 'priority', 'due_at',
                'planned_duration_minutes', 'unit', 'planned_quantity',
                'self_close_allowed', 'requires_photo_evidence', 'min_required_photos',
                'progress_weight', 'drawing_attachment_id', 'drawing_revision_id',
            ])));
            $task->save();

            return $task->fresh(['checklistItems', 'assignees', 'dependencies']);
        });
    }
}
