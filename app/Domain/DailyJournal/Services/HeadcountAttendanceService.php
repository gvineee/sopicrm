<?php

namespace App\Domain\DailyJournal\Services;

use App\Domain\Attendance\Models\AttendanceSession;
use Illuminate\Support\Carbon;

/**
 * Spec section 11: the journal's headcount field is "დასწრებიდან მიღებული
 * headcount" (derived from real attendance) plus a manually explained
 * variance — never a purely manual number pretending to be attendance-based.
 * This service is the ONLY place that computation happens, so the Create/
 * Submit actions (and any future report) get the same, real, DB-backed
 * count instead of re-deriving it ad hoc.
 *
 * Deliberately counts distinct EMPLOYEES with an attendance_sessions row for
 * this project/work_date — not raw session rows — since a single employee
 * can (rarely) have more than one session on the same date (e.g. a
 * corrected/split session) and must not be double-counted.
 */
class HeadcountAttendanceService
{
    public function computeForProjectAndDate(string $projectId, string|Carbon $date): int
    {
        $workDate = $date instanceof Carbon ? $date->toDateString() : $date;

        return AttendanceSession::query()
            ->where('project_id', $projectId)
            ->whereDate('work_date', $workDate)
            ->distinct('employee_id')
            ->count('employee_id');
    }
}
