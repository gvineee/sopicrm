<?php

namespace App\Domain\Contractors\Actions;

use App\Domain\Contractors\Models\ContractorContract;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Contract state machine: pending_approval -> active. The approver must not
 * be the contract's own submitter — enforced in
 * App\Policies\ContractorContractPolicy::approve (the authorization
 * boundary), not re-checked here, matching this codebase's convention of one
 * enforcement point per rule.
 */
class ApproveContractorContractAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(ContractorContract $contract, User $approver): ContractorContract
    {
        if ($contract->status !== 'pending_approval') {
            throw ValidationException::withMessages([
                'status' => 'დამტკიცება შესაძლებელია მხოლოდ pending_approval სტატუსიდან.',
            ]);
        }

        $contract->update([
            'status' => 'active',
            'approved_at' => now(),
            'approved_by_user_id' => $approver->id,
        ]);

        $this->audit->log(
            action: 'contractors.contract.approved',
            target: $contract,
            actor: $approver,
        );

        return $contract->refresh();
    }
}
