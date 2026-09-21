<?php

namespace App\Policies;

use App\Domain\Companies\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('companies.view');
    }

    public function view(User $user, Company $company): bool
    {
        return $company->organization_id === $user->current_organization_id
            && $user->can('companies.view')
            && ($user->isActiveMemberOfCompany($company->id) || $user->can('companies.manage'));
    }

    public function create(User $user): bool
    {
        return $user->can('companies.manage');
    }

    public function update(User $user, Company $company): bool
    {
        return $company->organization_id === $user->current_organization_id
            && $user->can('companies.manage');
    }

    public function delete(User $user, Company $company): bool
    {
        return $company->organization_id === $user->current_organization_id
            && $user->can('companies.manage');
    }
}
