<?php

namespace App\Domain\DailyJournal\Exceptions;

/**
 * docs/architecture.md §5 / spec section 19: "ყველა approval ინახავს target
 * version-ს: დამტკიცების შემდეგ შეცვლილი draft ვერ ჩაითვლება ძველად
 * დამტკიცებულად." Thrown by AcceptDailyReportAction/ReturnDailyReportAction
 * when the caller's `target_version` no longer matches the row's current
 * `version` — the controller translates this to HTTP 409 so the UI can show
 * ConflictState.vue rather than silently approving a since-changed draft.
 */
class StaleDailyReportVersionException extends DailyReportDomainException
{
    public function __construct(public readonly int $currentVersion, public readonly int $targetVersion)
    {
        parent::__construct(
            "ჟურნალის ჩანაწერი შეიცვალა თქვენი დათვალიერების შემდეგ (მიმდინარე ვერსია {$currentVersion}, თქვენი {$targetVersion}). გთხოვთ ხელახლა ჩატვირთოთ."
        );
    }
}
