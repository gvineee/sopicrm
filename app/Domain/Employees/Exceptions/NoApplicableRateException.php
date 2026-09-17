<?php

namespace App\Domain\Employees\Exceptions;

use RuntimeException;

/**
 * spec section 5 hard rule: "პრიორიტეტი: პროექტის ტარიფი → თანამშრომლის
 * ძირითადი ტარიფი; არარსებობისას დარიცხვა დაიბლოკოს" — when neither a
 * project-override rate nor a base rate is effective on the given work date,
 * accrual must be BLOCKED, never defaulted to zero and never guessed. Callers
 * (Payroll/Attendance calculation actions, once those modules build their
 * own accrual Action) must catch this and raise a visible anomaly, not
 * swallow it.
 */
class NoApplicableRateException extends RuntimeException
{
    public static function forEmployeeOnDate(string $employeeId, string $workDate, ?string $projectId): self
    {
        $projectNote = $projectId !== null ? " (project {$projectId})" : '';

        return new self(
            "თანამშრომლისთვის ({$employeeId}){$projectNote} {$workDate} თარიღზე მოქმედი ტარიფი ვერ მოიძებნა — დარიცხვა დაბლოკილია."
        );
    }
}
