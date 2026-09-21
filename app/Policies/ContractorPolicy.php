<?php

namespace App\Policies;

use App\Domain\Contractors\Models\Contractor;
use App\Models\User;

/** Org-level, not project-scoped — mirrors App\Policies\CompanyPolicy's view/manage split. */
class ContractorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('contractors.contractors.view');
    }

    public function view(User $user, Contractor $contractor): bool
    {
        return $contractor->organization_id === $user->organization_id
            && $user->can('contractors.contractors.view');
    }

    public function create(User $user): bool
    {
        return $user->can('contractors.contractors.manage');
    }

    public function update(User $user, Contractor $contractor): bool
    {
        return $contractor->organization_id === $user->organization_id
            && $user->can('contractors.contractors.manage');
    }
}
