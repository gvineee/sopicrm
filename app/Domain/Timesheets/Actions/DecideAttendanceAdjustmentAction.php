<?php

namespace App\Domain\Timesheets\Actions;

use App\Domain\Attendance\Models\AttendanceAdjustment;
use App\Domain\Shared\Models\Approval;
use App\Domain\Shared\Services\AuditLogger;
use App\Domain\Timesheets\DataTransferObjects\ApprovalDecisionData;
use App\Domain\Timesheets\Exceptions\InvalidTimesheetStateException;
use App\Domain\Timesheets\Exceptions\StaleApprovalVersionException;
use App\Domain\Timesheets\Support\SelfApprovalGuard;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Approve or reject a pending AttendanceAdjustment (spec section 7's
 * "დამმტკიცებელი" role in the manual correction form). Self-approval is
 * forbidden by default (spec section 3) and every decision re-checks the
 * `target_version` the approver read against the record's current version
 * (spec section 19) before committing.
 */
class DecideAttendanceAdjustmentAction
{
    public function __construct(
        private readonly SelfApprovalGuard $selfApprovalGuard,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function handle(AttendanceAdjustment $adjustment, ApprovalDecisionData $data, User $approver): AttendanceAdjustment
    {
        return DB::transaction(function () use ($adjustment, $data, $approver) {
            /** @var AttendanceAdjustment $locked */
            $locked = AttendanceAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw new InvalidTimesheetStateException('decide', $locked->status, 'pending');
            }

            if ($locked->version !== $data->targetVersion) {
                throw new StaleApprovalVersionException($data->targetVersion, $locked->version);
            }

            $this->selfApprovalGuard->assertAllowed(
                approver: $approver,
                subjectEmployeeId: $locked->employee_id,
                ownerExceptionAcknowledged: $data->ownerSelfApprovalExceptionAcknowledged,
                auditTarget: $locked,
                exceptionReason: $data->reason,
            );

            $locked->status = $data->decision === 'approved' ? 'approved' : 'rejected';
            $locked->approved_by_user_id = $approver->id;
            $locked->save();

            Approval::create([
                'approvable_type' => AttendanceAdjustment::class,
                'approvable_id' => $locked->id,
                'target_version' => $data->targetVersion,
                'approver_user_id' => $approver->id,
                'decision' => $data->decision,
                'reason' => $data->reason,
                'decided_at' => now(),
            ]);

            $this->auditLogger->log(
                action: "timesheets.adjustment.{$data->decision}",
                target: $locked,
                reason: $data->reason,
                actor: $approver,
            );

            return $locked;
        });
    }
}
