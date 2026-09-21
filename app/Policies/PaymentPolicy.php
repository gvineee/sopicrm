<?php

namespace App\Policies;

use App\Domain\Payroll\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('payroll.payments.view');
    }

    public function view(User $user, Payment $payment): bool
    {
        return $payment->organization_id === $user->organization_id
            && $user->can('payroll.payments.view');
    }

    public function create(User $user): bool
    {
        return $user->can('payroll.payments.manage');
    }
}
