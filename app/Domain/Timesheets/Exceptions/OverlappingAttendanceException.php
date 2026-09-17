<?php

namespace App\Domain\Timesheets\Exceptions;

/**
 * spec section 7 hard rule: "გადაფარული სესიები ... დაიბლოკოს" — a manual
 * correction's resulting window may never overlap another session the same
 * employee already has. Raised both as a friendly pre-check (at adjustment
 * creation, against existing attendance_sessions) and when the DB's own
 * exclusion constraint rejects the INSERT at approval time (the real
 * guarantee — see database/migrations/2026_09_16_090150_..., "attendance_
 * sessions_no_overlap").
 */
class OverlappingAttendanceException extends TimesheetDomainException
{
    public function __construct(string $employeeId, string $windowDescription)
    {
        parent::__construct(
            "Employee {$employeeId}'s corrected window ({$windowDescription}) overlaps an existing attendance session.",
            'attendance_adjustment_overlap',
            409,
            ['corrected_clock_in_at' => ['შესწორებული პერიოდი ემთხვევა უკვე არსებულ სესიას.']],
        );
    }
}
