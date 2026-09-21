<?php

namespace App\Domain\Timesheets\Actions;

use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Attendance\Models\Timesheet;
use App\Domain\Employees\Models\Employee;
use App\Domain\Payroll\Models\PayPeriod;
use App\Domain\Timesheets\Exceptions\InvalidTimesheetStateException;
use Illuminate\Support\Facades\DB;

/**
 * The piece REQ-TSH's other Actions (Submit/Approve/Reject/Lock) all assume
 * already exists: nothing else in this codebase ever creates a `timesheets`
 * row. Idempotent — safe to call repeatedly as new sessions close during the
 * pay period: finds-or-creates the employee's draft Timesheet for the period,
 * then calls BuildTimesheetLinesForSessionAction for every closed
 * AttendanceSession in the period that doesn't already have a line on this
 * timesheet (never duplicates a line for the same session).
 */
class GenerateTimesheetForPayPeriodAction
{
    public function __construct(private readonly BuildTimesheetLinesForSessionAction $buildLines) {}

    public function handle(Employee $employee, PayPeriod $payPeriod): Timesheet
    {
        return DB::transaction(function () use ($employee, $payPeriod) {
            $timesheet = Timesheet::query()->firstOrCreate(
                ['employee_id' => $employee->id, 'pay_period_id' => $payPeriod->id],
                ['status' => 'draft'],
            );

            if (! in_array($timesheet->status, ['draft'], true)) {
                throw new InvalidTimesheetStateException('generate lines for', $timesheet->status, 'draft');
            }

            $alreadyLinkedSessionIds = $timesheet->lines()->pluck('attendance_session_id')->filter();

            $sessions = AttendanceSession::query()
                ->where('employee_id', $employee->id)
                ->where('status', 'closed')
                ->whereBetween('work_date', [$payPeriod->starts_on, $payPeriod->ends_on])
                ->whereNotIn('id', $alreadyLinkedSessionIds)
                ->get();

            foreach ($sessions as $session) {
                if ($session->project_id === null) {
                    // REQ-ATT-08: unattributed sessions are skipped, never
                    // guessed — a human resolves the project via
                    // AttributeAttendanceSessionProjectAction first, then a
                    // rerun of this Action picks it up.
                    continue;
                }

                $this->buildLines->handle($timesheet, $session);
            }

            return $timesheet->fresh();
        });
    }
}
