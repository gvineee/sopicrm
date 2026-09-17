<?php

namespace App\Domain\Timesheets\Actions;

use App\Domain\Attendance\Models\Timesheet;
use App\Domain\Shared\Models\Approval;
use App\Domain\Shared\Services\AuditLogger;
use App\Domain\Timesheets\Exceptions\InvalidTimesheetStateException;
use App\Domain\Timesheets\Exceptions\StaleApprovalVersionException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * spec section 7: "Rejected ბრუნდება draft-ში მიზეზით" — a submitted
 * timesheet a reviewer sends back becomes `draft` again, with
 * `rejected_reason` populated, never a dead-end terminal state.
 */
class RejectTimesheetAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(Timesheet $timesheet, User $rejectedBy, int $targetVersion, string $reason): Timesheet
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => ['უარყოფის მიზეზი სავალდებულოა.'],
            ]);
        }

        return DB::transaction(function () use ($timesheet, $rejectedBy, $targetVersion, $reason) {
            /** @var Timesheet $locked */
            $locked = Timesheet::query()->whereKey($timesheet->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'submitted') {
                throw new InvalidTimesheetStateException('reject', $locked->status, 'submitted');
            }

            if ($locked->version !== $targetVersion) {
                throw new StaleApprovalVersionException($targetVersion, $locked->version);
            }

            $locked->status = 'draft';
            $locked->rejected_reason = $reason;
            $locked->save();

            Approval::create([
                'approvable_type' => Timesheet::class,
                'approvable_id' => $locked->id,
                'target_version' => $targetVersion,
                'approver_user_id' => $rejectedBy->id,
                'decision' => 'rejected',
                'reason' => $reason,
                'decided_at' => now(),
            ]);

            $this->auditLogger->log(
                action: 'timesheets.timesheet.rejected',
                target: $locked,
                reason: $reason,
                actor: $rejectedBy,
            );

            return $locked;
        });
    }
}
