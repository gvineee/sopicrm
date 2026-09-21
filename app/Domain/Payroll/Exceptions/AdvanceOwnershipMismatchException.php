<?php

namespace App\Domain\Payroll\Exceptions;

/**
 * MONEY-01 (docs/claude-platform-completion-2026-09-21.md, audit finding
 * D2): `RecordPaymentAction` selected an Advance by id alone, with no check
 * that it actually belongs to the employee the payment is being recorded
 * for — a request naming a different employee's advance id would silently
 * deduct against the wrong person's advance.
 */
class AdvanceOwnershipMismatchException extends PayrollDomainException
{
    public function __construct(string $advanceId, string $employeeId)
    {
        parent::__construct(
            "Advance {$advanceId} does not belong to employee {$employeeId}.",
            'advance_ownership_mismatch',
            422,
            ['deducts_advance_id' => ['მითითებული ავანსი არ ეკუთვნის ამ თანამშრომელს.']],
        );
    }
}
