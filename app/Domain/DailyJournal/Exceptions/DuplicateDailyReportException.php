<?php

namespace App\Domain\DailyJournal\Exceptions;

/**
 * `daily_reports` has `unique(organization_id, project_id, report_date)` —
 * one journal entry per project per calendar day. Thrown before hitting that
 * DB constraint so the controller can return a clear 409 pointing at the
 * existing draft instead of a raw SQL error.
 */
class DuplicateDailyReportException extends DailyReportDomainException
{
    public function __construct(public readonly string $existingReportId)
    {
        parent::__construct('ამ პროექტისთვის ამ თარიღით ჟურნალის ჩანაწერი უკვე არსებობს.');
    }
}
