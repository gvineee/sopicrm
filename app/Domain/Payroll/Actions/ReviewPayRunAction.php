<?php

namespace App\Domain\Payroll\Actions;

use App\Domain\Payroll\Exceptions\InvalidPayRunStateException;
use App\Domain\Payroll\Exceptions\StalePayRunVersionException;
use App\Domain\Payroll\Models\PayRun;
use App\Domain\Shared\Models\Approval;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * spec section 8 state machine: calculated -> reviewed. A generic
 * `Approval` row is written with `target_version` (spec section 19) so a
 * PayRun recalculated after being reviewed-then-rejected can never have that
 * stale review silently treated as still valid.
 */
class ReviewPayRunAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(PayRun $payRun, User $actor, int $expectedVersion, ?string $reason = null): PayRun
    {
        if ($payRun->status !== 'calculated') {
            throw new InvalidPayRunStateException($payRun->status, 'calculated', 'review');
        }

        if ($payRun->version !== $expectedVersion) {
            throw new StalePayRunVersionException($expectedVersion, $payRun->version);
        }

        return DB::transaction(function () use ($payRun, $actor, $expectedVersion, $reason) {
            $before = $payRun->only(['status']);

            Approval::query()->create([
                'approvable_type' => PayRun::class,
                'approvable_id' => $payRun->id,
                'target_version' => $expectedVersion,
                'approver_user_id' => $actor->id,
                'decision' => 'approved',
                'reason' => $reason,
                'decided_at' => now(),
            ]);

            $payRun->status = 'reviewed';
            $payRun->save();

            $this->auditLogger->log(
                action: 'payroll.pay_run.reviewed',
                target: $payRun,
                before: $before,
                after: $payRun->only(['status']),
                reason: $reason,
                actor: $actor,
            );

            return $payRun->refresh();
        });
    }
}
