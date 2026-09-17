<?php

namespace App\Domain\Payroll\Exceptions;

/**
 * Guards the spec section 8 outstanding-balance formula from going negative
 * via over-allocation: "გასაცემი ნაშთი = დამტკიცებული ანგარიშსწორების
 * თანხა − მასზე განაწილებული გადახდები − განაწილებული ავანსები." A
 * `payment_to_earnings` allocation may never exceed the employee's current
 * outstanding balance across the PayRunLines it targets.
 */
class PaymentAllocationExceedsBalanceException extends PayrollDomainException
{
    public function __construct(string $outstanding, string $requested)
    {
        parent::__construct(
            "Outstanding balance is {$outstanding} GEL; allocation of {$requested} GEL was requested.",
            'payment_allocation_exceeds_balance',
            422,
            ['amount' => ["გასაცემი ნაშთია {$outstanding} GEL; მოთხოვნილია {$requested} GEL."]],
        );
    }
}
