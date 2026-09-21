<?php

namespace App\Policies;

use App\Domain\Attendance\Models\Timesheet;
use App\Models\User;

class TimesheetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('timesheets.timesheets.view');
    }

    public function view(User $user, Timesheet $timesheet): bool
    {
        return $timesheet->organization_id === $user->organization_id
            && $user->can('timesheets.timesheets.view');
    }

    public function generate(User $user): bool
    {
        return $user->can('timesheets.timesheets.generate');
    }

    public function submit(User $user, Timesheet $timesheet): bool
    {
        return $timesheet->organization_id === $user->organization_id
            && $user->can('timesheets.timesheets.submit');
    }

    public function approve(User $user, Timesheet $timesheet): bool
    {
        return $timesheet->organization_id === $user->organization_id
            && $user->can('timesheets.timesheets.approve');
    }

    public function lock(User $user, Timesheet $timesheet): bool
    {
        return $timesheet->organization_id === $user->organization_id
            && $user->can('timesheets.timesheets.approve');
    }
}
