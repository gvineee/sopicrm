<?php

namespace App\Policies;

use App\Domain\Payroll\Models\DailyPayPolicy;
use App\Models\User;

class DailyPayPolicyPolicy
{
    public function view(User $user): bool
    {
        return $user->can('payroll.daily-pay-policy.view');
    }

    public function update(User $user, ?DailyPayPolicy $policy = null): bool
    {
        return $user->can('payroll.daily-pay-policy.manage');
    }
}
