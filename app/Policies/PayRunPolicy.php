<?php

namespace App\Policies;

use App\Domain\Payroll\Models\PayRun;
use App\Models\User;

class PayRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('payroll.pay-runs.view');
    }

    public function view(User $user, PayRun $payRun): bool
    {
        return $payRun->organization_id === $user->organization_id
            && $user->can('payroll.pay-runs.view');
    }

    public function create(User $user): bool
    {
        return $user->can('payroll.pay-runs.manage');
    }

    public function calculate(User $user, PayRun $payRun): bool
    {
        return $payRun->organization_id === $user->organization_id
            && $user->can('payroll.pay-runs.manage');
    }

    public function review(User $user, PayRun $payRun): bool
    {
        return $payRun->organization_id === $user->organization_id
            && $user->can('payroll.pay-runs.review');
    }

    public function approve(User $user, PayRun $payRun): bool
    {
        return $payRun->organization_id === $user->organization_id
            && $user->can('payroll.pay-runs.approve');
    }

    public function lock(User $user, PayRun $payRun): bool
    {
        return $payRun->organization_id === $user->organization_id
            && $user->can('payroll.pay-runs.approve');
    }

    public function adjust(User $user, PayRun $payRun): bool
    {
        return $payRun->organization_id === $user->organization_id
            && $user->can('payroll.adjustments.create');
    }
}
