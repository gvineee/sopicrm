<?php

namespace App\Domain\Timesheets\Exceptions;

/**
 * spec section 7 hard rule: "განაწილებული ანაზღაურებადი წუთების ჯამი ვერ
 * გადააჭარბებს თანამშრომლის დამტკიცებულ წუთებს" — when one day spans
 * multiple projects, the sum of payable minutes allocated across that day's
 * timesheet_lines may never exceed the employee's approved (closed
 * attendance_sessions) total for that work_date. Raised by
 * App\Domain\Timesheets\Actions\BuildTimesheetLinesForSessionAction inside
 * the same DB transaction as the aggregate check (a `SELECT ... FOR UPDATE`
 * lock on the sibling lines — see docs/data-model.md's own note that a plain
 * CHECK constraint can't aggregate across sibling rows in Postgres).
 */
class PayableMinutesExceedApprovedException extends TimesheetDomainException
{
    public function __construct(string $workDate, int $approvedMinutes, int $attemptedTotalMinutes)
    {
        parent::__construct(
            "Allocating {$attemptedTotalMinutes} minutes on {$workDate} would exceed the {$approvedMinutes} approved minutes for that work date.",
            'timesheet_line_exceeds_approved_minutes',
            422,
            ['payable_minutes' => ["განაწილებული წუთები ({$attemptedTotalMinutes}) აღემატება დამტკიცებულ წუთებს ({$approvedMinutes}) თარიღისთვის {$workDate}."]],
        );
    }
}
