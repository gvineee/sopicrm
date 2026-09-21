<?php

namespace App\Domain\Payroll\Actions;

use App\Domain\Employees\Models\Employee;
use App\Domain\Payroll\Exceptions\AdvanceOverAllocationException;
use App\Domain\Payroll\Exceptions\AdvanceOwnershipMismatchException;
use App\Domain\Payroll\Exceptions\PaymentAllocationExceedsBalanceException;
use App\Domain\Payroll\Exceptions\PaymentCurrencyMismatchException;
use App\Domain\Payroll\Models\Advance;
use App\Domain\Payroll\Models\Payment;
use App\Domain\Payroll\Models\PaymentAllocation;
use App\Domain\Payroll\Services\PayrollBalanceService;
use App\Domain\Payroll\Support\Money;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records a real-world payment already made through an external process
 * (spec/outer-task hard rule: this system never sends a real payment) and
 * allocates it either against the employee's outstanding earnings balance or
 * against a specific outstanding Advance — never both in one call, matching
 * `payment_allocations.allocation_type`'s exclusive enum. `pending` is never
 * treated as settling a balance (spec section 8 explicit) — this Action only
 * ever writes `status = 'completed'`, since a payment this system is told
 * about after the fact is, by definition, already done.
 *
 * MONEY-01 (docs/claude-platform-completion-2026-09-21.md, audit findings
 * D1/D2), three real bugs fixed here:
 *  - No row lock guarded the balance read — two concurrent requests for the
 *    same employee could both read the same stale outstanding balance and
 *    both succeed, together exceeding it. Fixed by locking the Employee row
 *    for the whole transaction, which serializes any concurrent payment
 *    against the same employee regardless of which branch (earnings vs.
 *    advance) each one takes.
 *  - An advance was selected by id alone, with no check that it belongs to
 *    the employee the payment is for. Fixed: `AdvanceOwnershipMismatchException`.
 *  - `$currency` was accepted freely with no check against what this system
 *    actually pays in. Fixed to a strict GEL-only policy for this first
 *    version (`PaymentCurrencyMismatchException`) rather than a full
 *    per-currency balance ledger — see that exception's own docblock.
 *  - `$requestId` (client-generated once per form submission, unchanged
 *    across any retry of that same submission) makes a retried request
 *    return the already-recorded payment instead of creating a duplicate.
 */
class RecordPaymentAction
{
    public function __construct(
        private readonly PayrollBalanceService $balance,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function execute(
        Employee $employee,
        string $amount,
        string $currency,
        string $method,
        ?string $reference,
        ?string $evidenceAttachmentId,
        User $actor,
        ?string $deductsAdvanceId = null,
        ?string $requestId = null,
    ): Payment {
        return DB::transaction(function () use ($employee, $amount, $currency, $method, $reference, $evidenceAttachmentId, $actor, $deductsAdvanceId, $requestId) {
            if ($requestId !== null) {
                $existing = Payment::query()->where('request_id', $requestId)->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            if ($currency !== 'GEL') {
                throw new PaymentCurrencyMismatchException('GEL', $currency);
            }

            // Locks the employee row for the whole transaction so a second,
            // concurrent RecordPaymentAction call for the SAME employee
            // blocks until this one commits — both the earnings-balance read
            // below and the advance-remaining read depend on the same
            // employee's payment history, so either branch must serialize
            // against the other.
            Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            $advance = null;

            if ($deductsAdvanceId !== null) {
                /** @var Advance $advance */
                $advance = Advance::query()->whereKey($deductsAdvanceId)->lockForUpdate()->firstOrFail();

                if ($advance->employee_id !== $employee->id) {
                    throw new AdvanceOwnershipMismatchException($advance->id, $employee->id);
                }

                if ($advance->currency !== 'GEL') {
                    throw new PaymentCurrencyMismatchException('GEL', $advance->currency);
                }

                $remaining = $this->balance->remainingOnAdvance($advance);

                if (Money::compare($amount, $remaining) > 0) {
                    throw new AdvanceOverAllocationException($advance->id, $remaining, $amount);
                }
            } else {
                $outstanding = $this->balance->outstandingForEmployee($employee->id);

                if (Money::compare($amount, $outstanding) > 0) {
                    throw new PaymentAllocationExceedsBalanceException($outstanding, $amount);
                }
            }

            $payment = Payment::query()->create([
                'employee_id' => $employee->id,
                'paid_at' => now(),
                'amount' => $amount,
                'currency' => $currency,
                'method' => $method,
                'reference' => $reference,
                'request_id' => $requestId,
                'evidence_attachment_id' => $evidenceAttachmentId,
                'status' => 'completed',
            ]);

            PaymentAllocation::query()->create([
                'payment_id' => $payment->id,
                'advance_id' => $advance?->id,
                'allocated_amount' => $amount,
                'allocation_type' => $advance !== null ? 'advance_deduction' : 'payment_to_earnings',
            ]);

            if ($advance !== null && Money::isZero($this->balance->remainingOnAdvance($advance))) {
                $advance->status = 'fully_deducted';
                $advance->save();
            }

            $this->auditLogger->log(
                action: 'payroll.payment.recorded',
                target: $payment,
                after: $payment->only(['employee_id', 'amount', 'method', 'reference']),
                actor: $actor,
            );

            return $payment->fresh();
        });
    }
}
