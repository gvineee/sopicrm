<?php

namespace App\Policies;

use App\Domain\Contractors\Models\ContractorContract;
use App\Models\User;

class ContractorContractPolicy
{
    public function view(User $user, ContractorContract $contract): bool
    {
        return $contract->organization_id === $user->organization_id
            && $user->can('contractors.contractors.view');
    }

    public function create(User $user): bool
    {
        return $user->can('contractors.contracts.manage');
    }

    public function update(User $user, ContractorContract $contract): bool
    {
        return $contract->organization_id === $user->organization_id
            && $user->can('contractors.contracts.manage');
    }

    public function submitForApproval(User $user, ContractorContract $contract): bool
    {
        return $this->update($user, $contract);
    }

    /**
     * The approver must not be the contract's own submitter — self-approval
     * is never allowed here, mirroring the codebase's Payroll precedent
     * (docs/decisions.md).
     */
    public function approve(User $user, ContractorContract $contract): bool
    {
        if ($contract->organization_id !== $user->organization_id) {
            return false;
        }

        if (! $user->can('contractors.contracts.approve')) {
            return false;
        }

        return $contract->submitted_by_user_id !== $user->id;
    }

    public function reject(User $user, ContractorContract $contract): bool
    {
        return $this->approve($user, $contract);
    }

    public function close(User $user, ContractorContract $contract): bool
    {
        return $contract->organization_id === $user->organization_id
            && $user->can('contractors.contracts.manage');
    }
}
