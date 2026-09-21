<?php

namespace App\Policies;

use App\Domain\Attendance\Models\AttendanceAdjustment;
use App\Models\User;

class AttendanceAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('timesheets.adjustments.view');
    }

    public function view(User $user, AttendanceAdjustment $adjustment): bool
    {
        return $adjustment->organization_id === $user->organization_id
            && $user->can('timesheets.adjustments.view');
    }

    public function request(User $user): bool
    {
        return $user->can('timesheets.adjustments.request');
    }

    public function decide(User $user, AttendanceAdjustment $adjustment): bool
    {
        return $adjustment->organization_id === $user->organization_id
            && $user->can('timesheets.adjustments.decide');
    }
}
