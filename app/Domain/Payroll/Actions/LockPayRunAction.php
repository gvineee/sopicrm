<?php

namespace App\Domain\Payroll\Actions;

use App\Domain\Payroll\Exceptions\InvalidPayRunStateException;
use App\Domain\Payroll\Exceptions\StalePayRunVersionException;
use App\Domain\Payroll\Models\PayRun;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * spec section 8 state machine: approved -> locked. Locking is final: after
 * this point every correction to the run's lines is a new PayAdjustment
 * reversal row (see CreatePayAdjustmentAction) — this Action itself never
 * mutates a line, only the PayRun's own status/timestamps.
 */
class LockPayRunAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(PayRun $payRun, User $actor, int $expectedVersion): PayRun
    {
        if ($payRun->status !== 'approved') {
            throw new InvalidPayRunStateException($payRun->status, 'approved', 'lock');
        }

        if ($payRun->version !== $expectedVersion) {
            throw new StalePayRunVersionException($expectedVersion, $payRun->version);
        }

        return DB::transaction(function () use ($payRun, $actor) {
            $before = $payRun->only(['status']);

            $payRun->status = 'locked';
            $payRun->save();

            $this->auditLogger->log(
                action: 'payroll.pay_run.locked',
                target: $payRun,
                before: $before,
                after: $payRun->only(['status']),
                actor: $actor,
            );

            return $payRun->refresh();
        });
    }
}
