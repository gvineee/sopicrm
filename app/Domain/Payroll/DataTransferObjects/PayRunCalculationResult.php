<?php

namespace App\Domain\Payroll\DataTransferObjects;

use App\Domain\Payroll\Models\PayRun;

/**
 * Return value of App\Domain\Payroll\Actions\CalculatePayRunAction — the
 * PayRun itself plus a list of Georgian, user-facing warnings for
 * days/employees that were deliberately skipped (blocked by minimum
 * attendance, no resolvable rate would instead throw and abort the whole
 * calculation, but a per-date skip is not fatal to the rest of the run) so
 * the review screen can surface them instead of silently under-paying
 * someone with no explanation.
 */
final class PayRunCalculationResult
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly PayRun $payRun,
        public readonly int $linesCreated,
        public readonly array $warnings,
    ) {}
}
