<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Tasks\Models\ChecklistItem;
use App\Models\User;

class ToggleChecklistItem
{
    public function execute(ChecklistItem $item, bool $checked, User $actor): ChecklistItem
    {
        $item->update([
            'is_checked' => $checked,
            'checked_by_user_id' => $checked ? $actor->id : null,
            'checked_at' => $checked ? now() : null,
        ]);

        return $item;
    }
}
