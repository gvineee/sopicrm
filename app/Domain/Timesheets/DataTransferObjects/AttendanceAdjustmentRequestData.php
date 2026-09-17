<?php

namespace App\Domain\Timesheets\DataTransferObjects;

use Carbon\CarbonInterface;

/**
 * Input shape for App\Domain\Timesheets\Actions\RequestAttendanceAdjustmentAction
 * — the manual correction form (spec section 7): employee, date, site,
 * corrected IN/OUT or hours, reason, evidence, author. Exactly one of
 * (correctedClockInAt + correctedClockOutAt) or correctedHours is normally
 * given; both null is only valid when `flagOnly` is true (the "late event
 * after lock, details to be filled in by a human reviewer" case — see
 * HandleLateArrivingEventAction).
 */
final readonly class AttendanceAdjustmentRequestData
{
    public function __construct(
        public string $employeeId,
        public string $workDate,
        public ?string $siteId,
        public ?CarbonInterface $correctedClockInAt,
        public ?CarbonInterface $correctedClockOutAt,
        public ?string $correctedHours,
        public string $reason,
        public ?string $evidenceAttachmentId,
        public string $requestedByUserId,
        public ?string $originalSessionId = null,
        public bool $forLockedPeriod = false,
        public bool $flagOnly = false,
    ) {}
}
