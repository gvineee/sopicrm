<?php

namespace App\Domain\Contractors\Services;

use App\Domain\Contractors\Models\ContractorActAcceptance;
use App\Domain\Contractors\Models\ContractorContract;

/**
 * Outstanding balance is always computed, never stored — mirrors the
 * codebase's "advance deducted exactly once" carefulness without needing a
 * payment-to-act allocation ledger: outstanding = sum(accepted amounts) -
 * sum(payments), both queried fresh (same `sum()`-on-the-join idiom
 * App\Domain\Tasks\Actions\AcceptTaskSubmission uses for its running total).
 */
class ContractorBalanceService
{
    public function acceptedTotal(ContractorContract $contract): float
    {
        return (float) ContractorActAcceptance::query()
            ->join('contractor_acts', 'contractor_acts.id', '=', 'contractor_act_acceptances.contractor_act_id')
            ->where('contractor_acts.contract_id', $contract->id)
            ->sum('contractor_act_acceptances.accepted_amount');
    }

    public function paidTotal(ContractorContract $contract): float
    {
        return (float) $contract->payments()->sum('amount');
    }

    public function outstanding(ContractorContract $contract): float
    {
        return $this->acceptedTotal($contract) - $this->paidTotal($contract);
    }
}
