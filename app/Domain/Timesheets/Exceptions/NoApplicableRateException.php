<?php

namespace App\Domain\Timesheets\Exceptions;

/**
 * spec section 5 hard rule: "პრიორიტეტი: პროექტის ტარიფი → თანამშრომლის
 * ძირითადი ტარიფი; არარსებობისას დარიცხვა დაიბლოკოს" — if neither a
 * project-override nor a base RateHistory row is effective for the employee
 * on the given date, accrual is blocked outright rather than silently
 * defaulting to some guessed rate (which would fabricate payroll policy —
 * a hard constraint this module must never violate).
 */
class NoApplicableRateException extends TimesheetDomainException
{
    public function __construct(string $employeeId, string $workDate, string $rateType)
    {
        parent::__construct(
            "No effective {$rateType} rate found for employee {$employeeId} on {$workDate}.",
            'timesheet_no_applicable_rate',
            422,
            ['rate_snapshot_id' => ["თანამშრომლისთვის არ მოიძებნა მოქმედი ტარიფი თარიღისთვის {$workDate}. დარიცხვა დაბლოკილია, სანამ ტარიფი არ განისაზღვრება."]],
        );
    }
}
