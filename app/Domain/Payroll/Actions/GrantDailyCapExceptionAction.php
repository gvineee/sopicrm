<?php

namespace App\Domain\Payroll\Actions;

use App\Domain\Payroll\Exceptions\InvalidPayRunStateException;
use App\Domain\Payroll\Models\PayRunLine;
use App\Domain\Payroll\Support\Money;
use App\Domain\Shared\Models\Approval;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * spec section 8 hard rule: a PayRunLine that CalculatePayRunAction capped
 * at `exceeds_daily_cap=true` (raw day-units exceeded the org's configured
 * max-per-work-date) pays only the capped amount until someone with the
 * separate `payroll.pay-runs.grant-daily-cap-exception` permission
 * explicitly approves the full, uncapped amount, with a reason — this
 * Action is that one approval path, never automatic.
 *
 * Only usable before the PayRun is approved (a correction after approval is
 * a reversal/adjustment, never a line edit — see CreatePayAdjustmentAction)
 * and CalculatePayRunAction refuses to recalculate a run that already has a
 * granted exception, so this action's effect is never silently discarded.
 */
class GrantDailyCapExceptionAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(PayRunLine $line, string $uncappedDayUnits, string $uncappedGrossAmount, User $actor, string $reason): PayRunLine
    {
        $payRun = $line->payRun;

        if (! in_array($payRun->status, ['calculated', 'reviewed'], true)) {
            throw new InvalidPayRunStateException($payRun->status, 'calculated or reviewed', 'grant a daily-cap exception');
        }

        return DB::transaction(function () use ($line, $uncappedDayUnits, $uncappedGrossAmount, $actor, $reason) {
            $approval = Approval::query()->create([
                'approvable_type' => PayRunLine::class,
                'approvable_id' => $line->id,
                'target_version' => $line->version,
                'approver_user_id' => $actor->id,
                'decision' => 'approved',
                'reason' => $reason,
                'decided_at' => now(),
            ]);

            $before = $line->only(['quantity', 'gross_amount', 'net_amount', 'exceeds_daily_cap']);

            $line->quantity = $uncappedDayUnits;
            $line->gross_amount = $uncappedGrossAmount;
            $line->net_amount = Money::add($uncappedGrossAmount, $line->adjustments_amount, 2);
            $line->exceeds_daily_cap = true;
            $line->daily_cap_exception_approval_id = $approval->id;
            $line->save();

            $this->auditLogger->log(
                action: 'payroll.pay_run_line.daily_cap_exception_granted',
                target: $line,
                before: $before,
                after: $line->only(['quantity', 'gross_amount', 'net_amount', 'exceeds_daily_cap']),
                reason: $reason,
                actor: $actor,
            );

            return $line->refresh();
        });
    }
}
