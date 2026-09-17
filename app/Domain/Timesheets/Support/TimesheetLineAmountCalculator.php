<?php

namespace App\Domain\Timesheets\Support;

use App\Domain\Attendance\Models\TimesheetLine;

/**
 * Pure calculation helper (not persisted — `timesheet_lines` has no amount
 * column of its own; the authoritative money row is
 * App\Domain\Payroll\Models\PayRunLine, built by the Payroll module from
 * approved timesheet lines). Exists so this module's own screens can show a
 * defensible estimated amount per line and so
 * spec section 23's mandatory test row ("ტარიფი იცვლება შუა ცვლაში: 2 სთ ×
 * 10 + 2 სთ × 15 → 50.00 GEL და ორი rate snapshot") is verifiable at the
 * Timesheets layer without needing the Payroll module to exist first.
 *
 * Hourly: payable_minutes / 60 × rate.amount (spec section 8 formula).
 * Daily: the resolved daily rate's amount is returned as-is (a
 * `timesheet_line`'s `payable_minutes` doesn't express day-units; the
 * Payroll module's day-unit-capping logic is out of this module's scope).
 */
final class TimesheetLineAmountCalculator
{
    /**
     * @return string GEL amount, rounded half-up to 0.01 (spec section 8).
     */
    public function amountFor(TimesheetLine $line): string
    {
        $rate = $line->rateSnapshot;

        if ($rate === null) {
            return '0.00';
        }

        if ($line->rate_type === 'daily') {
            return MoneyMath::roundHalfUp((string) $rate->amount, 2);
        }

        $hours = MoneyMath::divide((string) $line->payable_minutes, '60', 10);
        $amount = MoneyMath::multiply($hours, (string) $rate->amount, 10);

        return MoneyMath::roundHalfUp($amount, 2);
    }
}
