<?php

namespace App\Policies;

use App\Domain\Attendance\Models\ShiftAssignment;
use App\Models\User;

class ShiftAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('attendance.shift_assignments.view');
    }

    public function view(User $user, ShiftAssignment $assignment): bool
    {
        return $assignment->organization_id === $user->organization_id
            && $user->can('attendance.shift_assignments.view');
    }

    public function create(User $user): bool
    {
        return $user->can('attendance.shift_assignments.manage');
    }

    public function update(User $user, ShiftAssignment $assignment): bool
    {
        return $assignment->organization_id === $user->organization_id
            && $user->can('attendance.shift_assignments.manage');
    }
}
