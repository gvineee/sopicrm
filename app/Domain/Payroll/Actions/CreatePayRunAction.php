<?php

namespace App\Domain\Payroll\Actions;

use App\Domain\Payroll\Models\PayPeriod;
use App\Domain\Payroll\Models\PayRun;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use InvalidArgumentException;

/**
 * Nothing else in this codebase creates a `pay_runs` row — CalculatePayRunAction
 * only ever operates on one that already exists. One draft PayRun per
 * PayPeriod at a time (a second is pointless while the first is still
 * draft/calculated, and once it's reviewed/approved/locked, corrections go
 * through CreatePayAdjustmentAction against the existing run, never a second
 * parallel run for the same period).
 */
class CreatePayRunAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(PayPeriod $payPeriod, User $actor): PayRun
    {
        if (PayRun::query()->where('pay_period_id', $payPeriod->id)->whereIn('status', ['draft', 'calculated'])->exists()) {
            throw new InvalidArgumentException('This pay period already has an in-progress pay run.');
        }

        $payRun = PayRun::query()->create([
            'pay_period_id' => $payPeriod->id,
            'status' => 'draft',
        ]);

        $this->auditLogger->log(
            action: 'payroll.pay_run.created',
            target: $payRun,
            after: $payRun->only(['pay_period_id', 'status']),
            actor: $actor,
        );

        return $payRun;
    }
}
