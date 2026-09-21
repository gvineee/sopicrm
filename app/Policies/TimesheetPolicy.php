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

    /**
     * TIMESHEET-EMAIL-01: emailing a timesheet out is an outbound
     * communication carrying financial-adjacent data — gated on the same
     * `timesheets.timesheets.approve` permission as approve/lock (owner and
     * finance only), deliberately stricter than plain `view`, rather than a
     * new dedicated permission with no other use yet.
     */
    public function send(User $user, Timesheet $timesheet): bool
    {
        return $timesheet->organization_id === $user->organization_id
            && $user->can('timesheets.timesheets.approve');
    }
}
