<?php

namespace App\Domain\Payroll\Actions;

use App\Domain\Employees\Models\Employee;
use App\Domain\Payroll\Exceptions\InvalidPayRunStateException;
use App\Domain\Payroll\Exceptions\SelfApprovalNotAllowedException;
use App\Domain\Payroll\Exceptions\StalePayRunVersionException;
use App\Domain\Payroll\Models\PayRun;
use App\Domain\Shared\Models\Approval;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * spec section 8 state machine: reviewed -> approved. Also the concrete
 * enforcement point for spec section 3's "საკუთარი ... ფინანსური მოთხოვნის
 * საბოლოო დამტკიცება ნაგულისხმევად აკრძალულია" for THIS specific
 * financial approval: if the approver's own Employee record has a
 * PayRunLine inside the run being approved, approval is refused unless they
 * separately hold `payroll.pay-runs.approve-own` (never seeded by default —
 * an owner grants it by hand, per DEC in docs/decisions.md, and every grant
 * is itself an auditable role-assignment action outside this module).
 */
class ApprovePayRunAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(PayRun $payRun, User $actor, int $expectedVersion, ?string $reason = null): PayRun
    {
        if ($payRun->status !== 'reviewed') {
            throw new InvalidPayRunStateException($payRun->status, 'reviewed', 'approve');
        }

        if ($payRun->version !== $expectedVersion) {
            throw new StalePayRunVersionException($expectedVersion, $payRun->version);
        }

        $ownEmployee = Employee::query()->where('user_id', $actor->id)->first();

        if ($ownEmployee !== null
            && $payRun->lines()->where('employee_id', $ownEmployee->id)->exists()
            && ! $actor->can('payroll.pay-runs.approve-own')
        ) {
            throw new SelfApprovalNotAllowedException;
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

            $payRun->status = 'approved';
            $payRun->approved_at = now();
            $payRun->approved_by_user_id = $actor->id;
            $payRun->save();

            $this->auditLogger->log(
                action: 'payroll.pay_run.approved',
                target: $payRun,
                before: $before,
                after: $payRun->only(['status', 'approved_at', 'approved_by_user_id']),
                reason: $reason,
                actor: $actor,
            );

            return $payRun->refresh();
        });
    }
}
