<?php

namespace App\Domain\Payroll\Exceptions;

/**
 * docs/data-model.md "rate_histories": if no rate row resolves for a given
 * employee + work date + project, accrual is BLOCKED for that date — never
 * defaulted to zero or guessed (spec: "არარსებობისას დარიცხვა დაიბლოკოს").
 */
class PayrollBlockedException extends PayrollDomainException
{
    public function __construct(string $employeeId, string $workDate, string $reason)
    {
        parent::__construct(
            "Payroll accrual blocked for employee {$employeeId} on {$workDate}: {$reason}",
            'payroll_accrual_blocked',
            422,
            ['work_date' => ["დარიცხვა დაბლოკილია {$workDate}-ისთვის: {$reason}"]],
        );
    }
}
