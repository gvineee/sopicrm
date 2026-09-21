<?php

namespace App\Policies;

use App\Domain\Payroll\Models\PayPeriod;
use App\Models\User;

class PayPeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('payroll.pay-periods.view');
    }

    public function view(User $user, PayPeriod $payPeriod): bool
    {
        return $payPeriod->organization_id === $user->organization_id
            && $user->can('payroll.pay-periods.view');
    }

    public function create(User $user): bool
    {
        return $user->can('payroll.pay-periods.manage');
    }

    public function close(User $user, PayPeriod $payPeriod): bool
    {
        return $payPeriod->organization_id === $user->organization_id
            && $user->can('payroll.pay-periods.manage');
    }
}
