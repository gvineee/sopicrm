<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Tasks\Models\ChecklistItem;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Services\TaskAuditRecorder;
use App\Models\User;

/**
 * A checklist answer is evidence a reviewer relies on, so ticking or
 * un-ticking one is recorded with who did it — the Tasks domain previously
 * wrote no audit rows at all (DV-01).
 */
class ToggleChecklistItem
{
    public function __construct(private readonly TaskAuditRecorder $audit) {}

    public function execute(ChecklistItem $item, bool $checked, User $actor): ChecklistItem
    {
        $wasChecked = (bool) $item->is_checked;

        $item->update([
            'is_checked' => $checked,
            'checked_by_user_id' => $checked ? $actor->id : null,
            'checked_at' => $checked ? now() : null,
        ]);

        $task = Task::query()->find($item->task_id);

        if ($task !== null && $wasChecked !== $checked) {
            $this->audit->record(
                $checked ? 'tasks.checklist_item.checked' : 'tasks.checklist_item.unchecked',
                $task,
                $actor,
                before: ['is_checked' => $wasChecked],
                after: ['is_checked' => $checked, 'label' => $item->label],
                target: $item,
            );
        }

        return $item;
    }
}
