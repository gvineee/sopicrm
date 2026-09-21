<?php

namespace App\Policies;

use App\Domain\Payroll\Models\Advance;
use App\Models\User;

class AdvancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('payroll.advances.view');
    }

    public function view(User $user, Advance $advance): bool
    {
        return $advance->organization_id === $user->organization_id
            && $user->can('payroll.advances.view');
    }

    public function create(User $user): bool
    {
        return $user->can('payroll.advances.manage');
    }
}
