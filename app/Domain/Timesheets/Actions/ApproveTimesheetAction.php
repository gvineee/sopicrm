<?php

namespace App\Domain\Timesheets\Actions;

use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Attendance\Models\Timesheet;
use App\Domain\Shared\Models\Approval;
use App\Domain\Shared\Services\AuditLogger;
use App\Domain\Timesheets\Exceptions\InvalidTimesheetStateException;
use App\Domain\Timesheets\Exceptions\StaleApprovalVersionException;
use App\Domain\Timesheets\Support\CalculationPolicy;
use App\Domain\Timesheets\Support\SelfApprovalGuard;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * spec section 7: submitted → approved, snapshotting "წყაროების ვერსია და
 * გამოთვლის პოლიტიკა" (which source AttendanceSession rows + their
 * `version`, and which calculation-policy version, were used) so a later
 * change to source data or calculation rules can never silently reinterpret
 * an already-approved timesheet. Self-approval is forbidden by default
 * (spec section 3).
 */
class ApproveTimesheetAction
{
    public function __construct(
        private readonly SelfApprovalGuard $selfApprovalGuard,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function handle(
        Timesheet $timesheet,
        User $approver,
        int $targetVersion,
        bool $ownerSelfApprovalExceptionAcknowledged = false,
        ?string $exceptionReason = null,
    ): Timesheet {
        return DB::transaction(function () use ($timesheet, $approver, $targetVersion, $ownerSelfApprovalExceptionAcknowledged, $exceptionReason) {
            /** @var Timesheet $locked */
            $locked = Timesheet::query()->whereKey($timesheet->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'submitted') {
                throw new InvalidTimesheetStateException('approve', $locked->status, 'submitted');
            }

            if ($locked->version !== $targetVersion) {
                throw new StaleApprovalVersionException($targetVersion, $locked->version);
            }

            $this->selfApprovalGuard->assertAllowed(
                approver: $approver,
                subjectEmployeeId: $locked->employee_id,
                ownerExceptionAcknowledged: $ownerSelfApprovalExceptionAcknowledged,
                auditTarget: $locked,
                exceptionReason: $exceptionReason,
            );

            $sessionSnapshot = AttendanceSession::query()
                ->where('employee_id', $locked->employee_id)
                ->whereIn('id', $locked->lines()->pluck('attendance_session_id')->filter()->unique())
                ->get(['id', 'version'])
                ->map(fn (AttendanceSession $session) => ['id' => $session->id, 'version' => $session->version])
                ->values()
                ->all();

            $locked->status = 'approved';
            $locked->approved_at = now();
            $locked->approved_by_user_id = $approver->id;
            $locked->source_sessions_version_snapshot = [
                'sessions' => $sessionSnapshot,
                'calculation_policy_version' => CalculationPolicy::CURRENT_VERSION,
                'snapshotted_at' => now()->toIso8601String(),
            ];
            $locked->save();

            Approval::create([
                'approvable_type' => Timesheet::class,
                'approvable_id' => $locked->id,
                'target_version' => $targetVersion,
                'approver_user_id' => $approver->id,
                'decision' => 'approved',
                'reason' => $exceptionReason,
                'decided_at' => now(),
            ]);

            $this->auditLogger->log(
                action: 'timesheets.timesheet.approved',
                target: $locked,
                actor: $approver,
            );

            return $locked;
        });
    }
}
