<?php

namespace App\Domain\Payroll\Actions;

use App\Domain\Payroll\Models\PayPeriod;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use InvalidArgumentException;

class ClosePayPeriodAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(PayPeriod $payPeriod, User $actor): PayPeriod
    {
        if ($payPeriod->status !== 'open') {
            throw new InvalidArgumentException('Only an open pay period can be closed.');
        }

        $payPeriod->status = 'closed';
        $payPeriod->save();

        $this->auditLogger->log(
            action: 'payroll.pay_period.closed',
            target: $payPeriod,
            after: $payPeriod->only(['status']),
            actor: $actor,
        );

        return $payPeriod->fresh();
    }
}
