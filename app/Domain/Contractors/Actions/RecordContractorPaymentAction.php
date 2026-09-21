<?php

namespace App\Domain\Contractors\Actions;

use App\Domain\Contractors\Models\Contractor;
use App\Domain\Contractors\Models\ContractorContract;
use App\Domain\Contractors\Models\ContractorPayment;
use App\Domain\Contractors\Services\ContractorBalanceService;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MONEY-01 (docs/claude-platform-completion-2026-09-21.md, audit findings
 * D1/D2), three real bugs fixed here:
 *  - The balance check and the payment insert were not in one transaction,
 *    and nothing locked the contract — two concurrent payment requests for
 *    the same contract could both read the same stale outstanding balance
 *    and both succeed, together exceeding it.
 *  - `$currency` was accepted freely with no check against the contract's
 *    own `currency` — a mismatched-currency payment would silently corrupt
 *    `ContractorBalanceService`'s balance arithmetic (it sums raw numbers
 *    with no currency awareness).
 *  - `$requestId` (client-generated once per form submission, unchanged
 *    across any retry of that same submission) makes a retried request
 *    return the already-recorded payment instead of creating a duplicate.
 */
class RecordContractorPaymentAction
{
    public function __construct(
        private readonly ContractorBalanceService $balance,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(
        Contractor $contractor,
        ContractorContract $contract,
        string $amount,
        string $currency,
        string $paidOn,
        ?string $method,
        ?string $reference,
        ?string $notes,
        User $actor,
        ?string $requestId = null,
    ): ContractorPayment {
        if ($contract->contractor_id !== $contractor->id) {
            throw ValidationException::withMessages([
                'contract_id' => 'მითითებული კონტრაქტი არ ეკუთვნის ამ კონტრაქტორს.',
            ]);
        }

        if (! is_numeric($amount) || bccomp($amount, '0', 2) <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'გადახდის თანხა უნდა იყოს დადებითი რიცხვი.',
            ]);
        }

        if ($currency !== $contract->currency) {
            throw ValidationException::withMessages([
                'currency' => "გადახდის ვალუტა უნდა ემთხვეოდეს კონტრაქტის ვალუტას ({$contract->currency}).",
            ]);
        }

        return DB::transaction(function () use ($contractor, $contract, $amount, $currency, $paidOn, $method, $reference, $notes, $actor, $requestId) {
            if ($requestId !== null) {
                $existing = ContractorPayment::query()->where('request_id', $requestId)->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            // Locks the contract row so a concurrent payment request against
            // the SAME contract blocks until this one commits — the
            // outstanding-balance read below depends on every prior payment
            // already being visible.
            ContractorContract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();

            $outstanding = $this->balance->outstanding($contract);

            if (bccomp((string) $amount, (string) $outstanding, 2) === 1) {
                throw ValidationException::withMessages([
                    'amount' => "გადასახდელი ნაშთია {$outstanding} {$contract->currency}; მოთხოვნილია {$amount}.",
                ]);
            }

            $payment = ContractorPayment::query()->create([
                'contractor_id' => $contractor->id,
                'contract_id' => $contract->id,
                'amount' => $amount,
                'currency' => $currency,
                'paid_at' => $paidOn,
                'method' => $method,
                'reference' => $reference,
                'request_id' => $requestId,
                'notes' => $notes,
                'recorded_by_user_id' => $actor->id,
            ]);

            $this->audit->log(
                action: 'contractors.payment.recorded',
                target: $payment,
                after: $payment->only(['amount', 'currency', 'paid_at', 'method', 'reference']),
                actor: $actor,
            );

            return $payment;
        });
    }
}
