<?php

namespace App\Domain\Timesheets\Actions;

use App\Domain\Attendance\Models\Timesheet;
use App\Domain\Shared\Services\AuditLogger;
use App\Domain\Timesheets\Exceptions\InvalidTimesheetStateException;
use App\Domain\Timesheets\Exceptions\StaleApprovalVersionException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * spec section 7: draft → submitted (the first step of "draft → submitted →
 * approved → locked").
 */
class SubmitTimesheetAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(Timesheet $timesheet, User $submittedBy, int $targetVersion): Timesheet
    {
        return DB::transaction(function () use ($timesheet, $submittedBy, $targetVersion) {
            /** @var Timesheet $locked */
            $locked = Timesheet::query()->whereKey($timesheet->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'draft') {
                throw new InvalidTimesheetStateException('submit', $locked->status, 'draft');
            }

            if ($locked->version !== $targetVersion) {
                throw new StaleApprovalVersionException($targetVersion, $locked->version);
            }

            $locked->status = 'submitted';
            $locked->submitted_at = now();
            $locked->submitted_by_user_id = $submittedBy->id;
            $locked->save();

            $this->auditLogger->log(
                action: 'timesheets.timesheet.submitted',
                target: $locked,
                actor: $submittedBy,
            );

            return $locked;
        });
    }
}
