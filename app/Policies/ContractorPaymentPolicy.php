<?php

namespace App\Policies;

use App\Domain\Contractors\Models\ContractorContract;
use App\Models\User;

class ContractorPaymentPolicy
{
    public function view(User $user, ContractorContract $contract): bool
    {
        return $contract->organization_id === $user->organization_id
            && $user->can('contractors.payments.view');
    }

    public function create(User $user, ContractorContract $contract): bool
    {
        return $contract->organization_id === $user->organization_id
            && $user->can('contractors.payments.manage');
    }
}
