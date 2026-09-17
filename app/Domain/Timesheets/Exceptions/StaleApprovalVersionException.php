<?php

namespace App\Domain\Timesheets\Exceptions;

/**
 * spec section 19 hard rule: "დამტკიცების შემდეგ შეცვლილი draft ვერ
 * ჩაითვლება ძველად დამტკიცებულად" — every approval-type action re-checks
 * the target's current `version` against the `target_version` the caller
 * read before deciding, and rejects as 409 on mismatch rather than silently
 * approving something that changed underneath the approver.
 */
class StaleApprovalVersionException extends TimesheetDomainException
{
    public function __construct(int $expected, int $actual)
    {
        parent::__construct(
            "Expected version {$expected} but the record is now at version {$actual}.",
            'stale_version',
            409,
            ['version' => ["ჩანაწერი შეიცვალა თქვენს მიერ წაკითხვის შემდეგ (მოსალოდნელი ვერსია {$expected}, ამჟამინდელი {$actual}). განაახლეთ გვერდი."]],
        );
    }
}
