<?php

namespace App\Domain\Timesheets\Actions;

use App\Domain\Attendance\Models\Timesheet;
use App\Domain\Shared\Services\AuditLogger;
use App\Domain\Timesheets\Exceptions\InvalidTimesheetStateException;
use App\Domain\Timesheets\Exceptions\StaleApprovalVersionException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * spec section 7: approved → locked, the final state. Once locked, no
 * further raw-event or adjustment write path may mutate this timesheet's
 * lines directly — see App\Domain\Timesheets\Actions\HandleLateArrivingEventAction
 * and App\Domain\Timesheets\Support\TimesheetLockGuard.
 */
class LockTimesheetAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(Timesheet $timesheet, User $lockedBy, int $targetVersion): Timesheet
    {
        return DB::transaction(function () use ($timesheet, $lockedBy, $targetVersion) {
            /** @var Timesheet $locked */
            $locked = Timesheet::query()->whereKey($timesheet->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'approved') {
                throw new InvalidTimesheetStateException('lock', $locked->status, 'approved');
            }

            if ($locked->version !== $targetVersion) {
                throw new StaleApprovalVersionException($targetVersion, $locked->version);
            }

            $locked->status = 'locked';
            $locked->locked_at = now();
            $locked->save();

            $this->auditLogger->log(
                action: 'timesheets.timesheet.locked',
                target: $locked,
                actor: $lockedBy,
            );

            return $locked;
        });
    }
}
