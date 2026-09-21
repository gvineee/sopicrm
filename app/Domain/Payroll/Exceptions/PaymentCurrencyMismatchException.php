<?php

namespace App\Domain\Payroll\Exceptions;

/**
 * MONEY-01 (docs/claude-platform-completion-2026-09-21.md, audit finding
 * D2): every payment in this system is GEL — `RecordPaymentAction` never
 * accepted a currency other than what an advance/balance is actually
 * denominated in. Per the ticket's own escape hatch ("ან პირველ ვერსიაში
 * მკაცრად შეზღუდე ვალუტა კომპანიის დადასტურებულ წესზე"), this is a strict
 * single-currency (GEL) policy for the first version rather than a full
 * per-currency balance ledger.
 */
class PaymentCurrencyMismatchException extends PayrollDomainException
{
    public function __construct(string $expected, string $given)
    {
        parent::__construct(
            "Payment currency must be {$expected}; {$given} was given.",
            'payment_currency_mismatch',
            422,
            ['currency' => ["გადახდის ვალუტა უნდა იყოს {$expected}; მოცემულია {$given}."]],
        );
    }
}
