<?php

namespace App\Domain\Timesheets\DataTransferObjects;

/**
 * Input shape for the Timesheets module's approval-type Actions
 * (DecideAttendanceAdjustmentAction, ApproveTimesheetAction,
 * RejectTimesheetAction). `targetVersion` implements spec section 19's
 * optimistic-concurrency rule (compared against the record's current
 * `version`; mismatch raises StaleApprovalVersionException, 409).
 */
final readonly class ApprovalDecisionData
{
    public function __construct(
        public string $approverUserId,
        public int $targetVersion,
        public string $decision,
        public ?string $reason = null,
        public bool $ownerSelfApprovalExceptionAcknowledged = false,
    ) {}
}
