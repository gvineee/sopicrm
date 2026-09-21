<?php

namespace App\Policies;

use App\Domain\Attendance\Models\AttendanceSession;
use App\Models\User;

class AttendanceSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('attendance.sessions.view');
    }

    public function view(User $user, AttendanceSession $session): bool
    {
        return $session->organization_id === $user->organization_id
            && $user->can('attendance.sessions.view');
    }

    public function reconstruct(User $user): bool
    {
        return $user->can('attendance.sessions.manage');
    }

    public function attributeProject(User $user, AttendanceSession $session): bool
    {
        return $session->organization_id === $user->organization_id
            && $user->can('attendance.sessions.manage');
    }
}
