<?php

namespace App\Domain\Contractors\Actions;

use App\Domain\Contractors\Models\ContractorContract;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Contract state machine: pending_approval -> draft, with a required reason. */
class RejectContractorContractAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(ContractorContract $contract, User $approver, string $reason): ContractorContract
    {
        if ($contract->status !== 'pending_approval') {
            throw ValidationException::withMessages([
                'status' => 'უარყოფა შესაძლებელია მხოლოდ pending_approval სტატუსიდან.',
            ]);
        }

        $contract->update([
            'status' => 'draft',
            'rejection_reason' => $reason,
        ]);

        $this->audit->log(
            action: 'contractors.contract.rejected',
            target: $contract,
            reason: $reason,
            actor: $approver,
        );

        return $contract->refresh();
    }
}
