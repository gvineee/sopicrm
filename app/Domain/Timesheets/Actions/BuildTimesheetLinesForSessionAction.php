<?php

namespace App\Domain\Timesheets\Actions;

use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Attendance\Models\Timesheet;
use App\Domain\Attendance\Models\TimesheetLine;
use App\Domain\Timesheets\Exceptions\InvalidAdjustmentInputException;
use App\Domain\Timesheets\Exceptions\NoApplicableRateException;
use App\Domain\Timesheets\Exceptions\PayableMinutesExceedApprovedException;
use App\Domain\Timesheets\Support\RateResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns one approved AttendanceSession into one or more TimesheetLine rows
 * on a draft Timesheet — this is where spec section 7's two adjacent hard
 * rules meet:
 *
 *  - "ერთ დღეს რამდენიმე ობიექტზე მუშაობისას დრო მიეკუთვნოს შესაბამის
 *    პროექტებს. განაწილებული ანაზღაურებადი წუთების ჯამი ვერ გადააჭარბებს
 *    თანამშრომლის დამტკიცებულ წუთებს" — enforced by locking and summing the
 *    day's existing lines against the day's approved session minutes before
 *    inserting.
 *  - "ტარიფის ცვლილების ზღვარზე საათები შესაბამის პერიოდებად დაიყოს" — a
 *    session's minutes only ever span two different effective RateHistory
 *    rows when the session itself crosses a calendar-day boundary (rates
 *    are date-scoped, not time-of-day-scoped — see docs/decisions.md for
 *    why this is the only schema-consistent reading of "mid-shift rate
 *    change"). Each calendar-day segment becomes its own TimesheetLine with
 *    its own `rate_snapshot_id`, even though every segment still carries
 *    the *session's* `work_date` (the shift's start date — spec: "ღამის
 *    ცვლა მიეკუთვნოს start-date-ს ტაბელში").
 *
 * Deliberately takes only an AttendanceSession as its payable-minutes
 * source (REQ-TSH-08: "მხოლოდ დავალების დახურვა ან task timer ხელფასის
 * დამოუკიდებელ წყაროდ არ გამოიყენო") — there is no Task/TaskTimer parameter
 * anywhere in this class, by construction, so a payroll line can never
 * trace back to one.
 */
class BuildTimesheetLinesForSessionAction
{
    public function __construct(private readonly RateResolver $rateResolver) {}

    /**
     * @return list<TimesheetLine>
     */
    public function handle(Timesheet $timesheet, AttendanceSession $session): array
    {
        if ($session->status !== 'closed' || $session->payable_minutes === null) {
            throw InvalidAdjustmentInputException::make(
                'Only a closed session with resolved payable minutes can be turned into timesheet lines.',
                ['attendance_session_id' => ['სესია არ არის დახურული ან არ აქვს გამოთვლილი ანაზღაურებადი დრო.']],
            );
        }

        if ($session->project_id === null) {
            throw InvalidAdjustmentInputException::make(
                'A session must be attributed to a project before it can become a timesheet line.',
                ['project_id' => ['სესია არ არის მიბმული პროექტზე.']],
            );
        }

        $segments = $this->splitByCalendarDay($session);

        return DB::transaction(function () use ($timesheet, $session, $segments) {
            $lines = [];

            foreach ($segments as $segment) {
                $lines[] = $this->createLineForSegment($timesheet, $session, $segment);
            }

            return $lines;
        });
    }

    /**
     * @param  array{date: string, minutes: int}  $segment
     */
    private function createLineForSegment(Timesheet $timesheet, AttendanceSession $session, array $segment): TimesheetLine
    {
        // Lock the sibling lines for this employee's work_date (the
        // session's attributed start-date, not the segment's own calendar
        // date) so two concurrent requests can never both squeeze past the
        // approved-minutes cap.
        $existingMinutes = (int) TimesheetLine::query()
            ->where('timesheet_id', $timesheet->id)
            ->where('work_date', $session->work_date)
            ->lockForUpdate()
            ->sum('payable_minutes');

        $approvedMinutes = (int) AttendanceSession::query()
            ->where('employee_id', $timesheet->employee_id)
            ->where('work_date', $session->work_date)
            ->where('status', 'closed')
            ->sum('payable_minutes');

        if ($existingMinutes + $segment['minutes'] > $approvedMinutes) {
            throw new PayableMinutesExceedApprovedException(
                (string) $session->work_date,
                $approvedMinutes,
                $existingMinutes + $segment['minutes'],
            );
        }

        $rate = $this->rateResolver->resolve(
            $timesheet->employee_id,
            $session->project_id,
            CarbonImmutable::parse($segment['date']),
            'hourly',
        );

        if ($rate === null) {
            throw new NoApplicableRateException($timesheet->employee_id, $segment['date'], 'hourly');
        }

        return TimesheetLine::create([
            'timesheet_id' => $timesheet->id,
            'work_date' => $session->work_date,
            'project_id' => $session->project_id,
            'attendance_session_id' => $session->id,
            'payable_minutes' => $segment['minutes'],
            'rate_type' => $rate->rate_type,
            'rate_snapshot_id' => $rate->id,
        ]);
    }

    /**
     * Splits a session's `payable_minutes` proportionally across the
     * calendar day(s) its raw clock-in/clock-out span touches, using
     * largest-remainder allocation so the parts always sum exactly to the
     * original total (docs/architecture.md §5's deterministic-remainder
     * convention). A same-day session returns a single segment.
     *
     * @return list<array{date: string, minutes: int}>
     */
    private function splitByCalendarDay(AttendanceSession $session): array
    {
        $clockIn = $session->clock_in_at;
        $clockOut = $session->clock_out_at;

        if ($clockOut === null || $clockIn->isSameDay($clockOut) || (int) $session->raw_duration_minutes <= 0) {
            return [['date' => $clockIn->toDateString(), 'minutes' => (int) $session->payable_minutes]];
        }

        $midnight = $clockIn->copy()->addDay()->startOfDay();
        $rawTotal = (int) $session->raw_duration_minutes;
        $rawBeforeMidnight = max(0, min($rawTotal, (int) $clockIn->diffInMinutes($midnight)));
        $rawAfterMidnight = $rawTotal - $rawBeforeMidnight;

        $payableTotal = (int) $session->payable_minutes;

        // Largest-remainder (Hamilton) allocation: floor each share, then
        // hand the leftover minute(s) to the segment with the largest
        // fractional remainder, so the two segments always sum exactly to
        // $payableTotal.
        $exactBefore = ($payableTotal * $rawBeforeMidnight) / $rawTotal;
        $exactAfter = ($payableTotal * $rawAfterMidnight) / $rawTotal;

        $before = (int) floor($exactBefore);
        $after = (int) floor($exactAfter);
        $remainder = $payableTotal - $before - $after;

        if ($remainder > 0) {
            if (($exactBefore - $before) >= ($exactAfter - $after)) {
                $before += $remainder;
            } else {
                $after += $remainder;
            }
        }

        $segments = [];
        if ($before > 0) {
            $segments[] = ['date' => $clockIn->toDateString(), 'minutes' => $before];
        }
        if ($after > 0) {
            $segments[] = ['date' => $midnight->toDateString(), 'minutes' => $after];
        }

        return $segments === [] ? [['date' => $clockIn->toDateString(), 'minutes' => 0]] : $segments;
    }
}
