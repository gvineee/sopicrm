<?php

namespace App\Domain\Contractors\Actions;

use App\Domain\Contractors\Models\ContractorContract;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Contract state machine: active -> closed (terminal). */
class CloseContractorContractAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(ContractorContract $contract, User $actor): ContractorContract
    {
        if ($contract->status !== 'active') {
            throw ValidationException::withMessages([
                'status' => 'დახურვა შესაძლებელია მხოლოდ active სტატუსიდან.',
            ]);
        }

        $contract->update(['status' => 'closed']);

        $this->audit->log(
            action: 'contractors.contract.closed',
            target: $contract,
            actor: $actor,
        );

        return $contract->refresh();
    }
}
