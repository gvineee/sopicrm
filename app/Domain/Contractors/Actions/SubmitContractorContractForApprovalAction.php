<?php

namespace App\Domain\Contractors\Actions;

use App\Domain\Contractors\Models\ContractorContract;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Contract state machine: draft -> pending_approval. */
class SubmitContractorContractForApprovalAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(ContractorContract $contract, User $actor): ContractorContract
    {
        if ($contract->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => 'დამტკიცებაზე გაგზავნა შესაძლებელია მხოლოდ draft სტატუსიდან.',
            ]);
        }

        $contract->update([
            'status' => 'pending_approval',
            'submitted_for_approval_at' => now(),
            'submitted_by_user_id' => $actor->id,
        ]);

        $this->audit->log(
            action: 'contractors.contract.submitted_for_approval',
            target: $contract,
            actor: $actor,
        );

        return $contract->refresh();
    }
}
