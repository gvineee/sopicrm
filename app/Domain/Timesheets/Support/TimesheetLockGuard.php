<?php

namespace App\Domain\Timesheets\Support;

use App\Domain\Attendance\Models\Timesheet;
use App\Domain\Payroll\Models\PayPeriod;
use Carbon\CarbonInterface;

/**
 * spec section 7 hard rule: "Locked პერიოდში დაგვიანებული მოვლენა ქმნის
 * adjustment request-ს; ისტორიულ ხელფასს ჩუმად არ ცვლის." Every write path
 * that could otherwise touch an already-locked Timesheet's lines
 * (App\Domain\Timesheets\Actions\BuildTimesheetLinesForSessionAction,
 * App\Domain\Timesheets\Actions\HandleLateArrivingEventAction) asks this
 * class first instead of re-deriving the "is this date locked" query
 * itself.
 */
final class TimesheetLockGuard
{
    public function timesheetFor(string $employeeId, CarbonInterface $workDate): ?Timesheet
    {
        $payPeriod = PayPeriod::query()
            ->where('starts_on', '<=', $workDate->toDateString())
            ->where('ends_on', '>=', $workDate->toDateString())
            ->first();

        if ($payPeriod === null) {
            return null;
        }

        return Timesheet::query()
            ->where('employee_id', $employeeId)
            ->where('pay_period_id', $payPeriod->id)
            ->first();
    }

    public function isWorkDateLocked(string $employeeId, CarbonInterface $workDate): bool
    {
        return $this->timesheetFor($employeeId, $workDate)?->status === 'locked';
    }
}
