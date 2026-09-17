<?php

namespace App\Domain\Timesheets\Actions;

use App\Domain\Attendance\Models\AttendanceAdjustment;
use App\Domain\Employees\Models\Employee;
use App\Domain\Timesheets\DataTransferObjects\AttendanceAdjustmentRequestData;
use App\Domain\Timesheets\Support\TimesheetLockGuard;
use App\Domain\Timesheets\Support\WorkDateResolver;
use Carbon\CarbonInterface;

/**
 * spec section 7 hard rule: "Locked პერიოდში დაგვიანებული მოვლენა ქმნის
 * adjustment request-ს; ისტორიულ ხელფასს ჩუმად არ ცვლის." Called by
 * whatever ingests a raw access event that arrives after its work date's
 * Timesheet is already locked (the Attendance module's reconstruction
 * pipeline, REQ-ATT scope, is the intended real caller — this Action is the
 * documented, testable contract it must call instead of mutating a locked
 * period directly).
 *
 * This deliberately creates only a *flagged, detail-free* pending
 * AttendanceAdjustment (`for_locked_period = true`, no proposed
 * correction) — it does not attempt to compute what the "right" correction
 * would be, since that requires the full session-reconstruction machinery
 * this module does not own. A human (HR/finance) reviews the flag and, if a
 * correction is warranted, drives it through the normal
 * RequestAttendanceAdjustmentAction/approval flow as a separate, explicit,
 * auditable step — never a silent rewrite of an already-locked pay period.
 */
class HandleLateArrivingEventAction
{
    public function __construct(private readonly RequestAttendanceAdjustmentAction $requestAdjustment) {}

    /**
     * @throws \RuntimeException when the event's work date is not actually locked —
     *                           this Action exists only for the locked-period case;
     *                           an on-time/unlocked-period late event belongs to the
     *                           normal Attendance reconstruction pipeline instead.
     */
    public function handle(
        Employee $employee,
        CarbonInterface $rawEventTime,
        string $requestedByUserId,
        ?string $siteId,
        string $sourceDescription,
    ): AttendanceAdjustment {
        $workDate = WorkDateResolver::startDateFor($rawEventTime);

        if (! app(TimesheetLockGuard::class)->isWorkDateLocked($employee->id, $workDate)) {
            throw new \RuntimeException(
                'HandleLateArrivingEventAction was called for a work date whose timesheet is not locked; '
                .'route this event through the normal Attendance reconstruction pipeline instead.'
            );
        }

        return $this->requestAdjustment->handle(new AttendanceAdjustmentRequestData(
            employeeId: $employee->id,
            workDate: $workDate->toDateString(),
            siteId: $siteId,
            correctedClockInAt: null,
            correctedClockOutAt: null,
            correctedHours: null,
            reason: "დაგვიანებული მოვლენა უკვე ჩაკეტილ პერიოდში ({$sourceDescription}, დრო: {$rawEventTime->toIso8601String()}) — საჭიროებს ხელით განხილვას.",
            evidenceAttachmentId: null,
            requestedByUserId: $requestedByUserId,
            originalSessionId: null,
            forLockedPeriod: true,
            flagOnly: true,
        ));
    }
}
