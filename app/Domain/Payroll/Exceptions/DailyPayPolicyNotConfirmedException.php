<?php

namespace App\Domain\Payroll\Exceptions;

/**
 * spec section 8 hard rule + docs/data-model.md open question #4: the
 * full/half-day threshold, minimum-attendance rule, and incomplete-day
 * behavior must be a configurable record an accountant has explicitly
 * confirmed — never a hardcoded/guessed default. While an organization's
 * DailyPayPolicy is unconfirmed (or missing entirely), daily-basis PayRun
 * calculation fails closed with this exception rather than silently using
 * placeholder thresholds.
 */
class DailyPayPolicyNotConfirmedException extends PayrollDomainException
{
    public function __construct()
    {
        parent::__construct(
            'No accountant-confirmed daily pay policy exists for this organization; daily-basis payroll calculation is blocked.',
            'daily_pay_policy_not_confirmed',
            422,
            ['daily_pay_policy' => ['დღიური ანაზღაურების პოლიტიკა ჯერ არ არის ბუღალტრის მიერ დადასტურებული — დღიური ტიპის დარიცხვა დაბლოკილია.']],
        );
    }
}
