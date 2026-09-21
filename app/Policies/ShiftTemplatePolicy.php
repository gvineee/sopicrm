<?php

namespace App\Policies;

use App\Domain\Attendance\Models\ShiftTemplate;
use App\Models\User;

class ShiftTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('attendance.shift_templates.view');
    }

    public function view(User $user, ShiftTemplate $template): bool
    {
        return $template->organization_id === $user->organization_id
            && $user->can('attendance.shift_templates.view');
    }

    public function create(User $user): bool
    {
        return $user->can('attendance.shift_templates.manage');
    }

    public function update(User $user, ShiftTemplate $template): bool
    {
        return $template->organization_id === $user->organization_id
            && $user->can('attendance.shift_templates.manage');
    }

    public function delete(User $user, ShiftTemplate $template): bool
    {
        return $template->organization_id === $user->organization_id
            && $user->can('attendance.shift_templates.manage');
    }
}
