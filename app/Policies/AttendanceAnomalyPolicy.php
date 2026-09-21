<?php

namespace App\Policies;

use App\Domain\Attendance\Models\AttendanceAnomaly;
use App\Models\User;

class AttendanceAnomalyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('attendance.anomalies.view');
    }

    public function view(User $user, AttendanceAnomaly $anomaly): bool
    {
        return $anomaly->organization_id === $user->organization_id
            && $user->can('attendance.anomalies.view');
    }

    public function resolve(User $user, AttendanceAnomaly $anomaly): bool
    {
        return $anomaly->organization_id === $user->organization_id
            && $user->can('attendance.anomalies.resolve');
    }
}
