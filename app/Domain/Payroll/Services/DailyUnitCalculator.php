<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Payroll\Models\DailyPayPolicy;

/**
 * Converts one employee's total approved payable minutes for one calendar
 * work-date (summed across every site/project they worked that date — spec
 * section 8: a multi-site day must not multiply the daily rate) into a
 * day-unit quantity, per the organization's confirmed DailyPayPolicy. Pure
 * function of (minutes, policy) — no I/O, so it is trivially unit-testable
 * against every row of the policy's threshold table.
 */
class DailyUnitCalculator
{
    /**
     * @return DailyUnitResult day-unit quantity BEFORE the max-per-work-date
     *                         cap is applied (capping is CalculatePayRunAction's
     *                         job, since it also needs to know about any
     *                         granted exception)
     */
    public function calculate(int $totalMinutes, DailyPayPolicy $policy): DailyUnitResult
    {
        if ($totalMinutes >= $policy->full_day_threshold_minutes) {
            return new DailyUnitResult('1.00', 'full_day');
        }

        if ($policy->half_day_threshold_minutes !== null && $totalMinutes >= $policy->half_day_threshold_minutes) {
            return new DailyUnitResult('0.50', 'half_day');
        }

        if ($totalMinutes < $policy->minimum_attendance_minutes) {
            return new DailyUnitResult('0.00', 'below_minimum_attendance');
        }

        // Between minimum attendance and the half/full threshold: the
        // organization's configured incomplete-day behavior decides — spec
        // section 8: "არასრული დღის ქცევა ... ზღვარი არ ჩაიკეროს კოდში."
        return match ($policy->incomplete_day_behavior) {
            'pay_half_day' => new DailyUnitResult('0.50', 'incomplete_paid_half_day'),
            'pay_prorated_hourly' => new DailyUnitResult(
                // Fraction of a full day proportional to minutes actually
                // worked, e.g. 300/480 = 0.625 day-units. Documented,
                // routine interpretation of "prorated hourly" for a
                // daily-rate employee who has no hourly rate of their own —
                // see docs/decisions.md.
                bcdiv((string) $totalMinutes, (string) $policy->full_day_threshold_minutes, 6),
                'incomplete_prorated'
            ),
            default => new DailyUnitResult('0.00', 'incomplete_blocked'),
        };
    }
}

/**
 * @internal value object returned by DailyUnitCalculator::calculate()
 */
final class DailyUnitResult
{
    public function __construct(
        /** @var numeric-string */
        public readonly string $dayUnits,
        public readonly string $reason,
    ) {}

    public function isPayable(): bool
    {
        return bccomp($this->dayUnits, '0', 6) > 0;
    }
}
