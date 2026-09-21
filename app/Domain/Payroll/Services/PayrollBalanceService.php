<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Payroll\Models\Advance;
use App\Domain\Payroll\Models\PaymentAllocation;
use App\Domain\Payroll\Models\PayRunLine;
use App\Domain\Payroll\Support\Money;

/**
 * spec section 8 exact formula: "გასაცემი ნაშთი = დამტკიცებული
 * ანგარიშსწორების თანხა − მასზე განაწილებული გადახდები − განაწილებული
 * ავანსები." Grouped by the Payment's own `employee_id` (always present,
 * unlike `payment_allocations.pay_run_line_id`, which is nullable to support
 * "a payment can also settle a standalone advance-only balance" per
 * `payments`' own docblock) rather than joining through PayRunLine, so a
 * payment/advance-deduction with no linked PayRunLine still counts.
 */
final class PayrollBalanceService
{
    public function outstandingForEmployee(string $employeeId): string
    {
        $approvedNet = PayRunLine::query()
            ->where('employee_id', $employeeId)
            ->whereHas('payRun', fn ($q) => $q->whereIn('status', ['approved', 'locked']))
            ->pluck('net_amount')
            ->reduce(fn (string $carry, mixed $v) => Money::add($carry, (string) $v, 2), '0');

        $allocated = PaymentAllocation::query()
            ->whereIn('allocation_type', ['payment_to_earnings', 'advance_deduction'])
            ->whereHas('payment', fn ($q) => $q->where('employee_id', $employeeId))
            ->pluck('allocated_amount')
            ->reduce(fn (string $carry, mixed $v) => Money::add($carry, (string) $v, 2), '0');

        return Money::sub($approvedNet, $allocated, 2);
    }

    public function remainingOnAdvance(Advance $advance): string
    {
        $deducted = PaymentAllocation::query()
            ->where('advance_id', $advance->id)
            ->where('allocation_type', 'advance_deduction')
            ->pluck('allocated_amount')
            ->reduce(fn (string $carry, mixed $v) => Money::add($carry, (string) $v, 2), '0');

        return Money::sub((string) $advance->amount, $deducted, 2);
    }
}
