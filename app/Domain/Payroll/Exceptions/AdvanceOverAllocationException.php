<?php

namespace App\Domain\Payroll\Exceptions;

/**
 * spec section 8 hard rule: "ავანსი მხოლოდ ერთხელ გამოიქვითოს" — an advance
 * is deducted from a payable balance at most once, via the append-only
 * `payment_allocations` ledger. Raised when a requested `advance_deduction`
 * allocation would push the sum of that advance's own deduction allocations
 * past its own `amount` (i.e. deducting more than the advance is actually
 * worth, or deducting an already-fully-deducted advance again).
 */
class AdvanceOverAllocationException extends PayrollDomainException
{
    public function __construct(string $advanceId, string $remaining, string $requested)
    {
        parent::__construct(
            "Advance {$advanceId} has only {$remaining} GEL remaining to deduct; {$requested} GEL was requested.",
            'advance_over_allocation',
            422,
            ['amount' => ["ავანსის დარჩენილი გამოსაქვითი თანხაა {$remaining} GEL; მოთხოვნილია {$requested} GEL."]],
        );
    }
}
