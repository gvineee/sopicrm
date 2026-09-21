<?php

namespace App\Domain\Timesheets\Exceptions;

/**
 * TIMESHEET-EMAIL-01: raised when a retry is attempted on a delivery row
 * that isn't `failed` — most importantly, a `sent` delivery must never be
 * retried through this path (that would silently re-send an email that
 * already succeeded once).
 */
class EmailDeliveryNotRetryableException extends TimesheetDomainException
{
    public function __construct(string $currentStatus)
    {
        parent::__construct(
            "Cannot retry a timesheet email delivery in status [{$currentStatus}]; only [failed] is retryable.",
            'timesheet_email_not_retryable',
            409,
            ['status' => ["ამ გაგზავნის სტატუსი ({$currentStatus}) ხელახლა გაგზავნის საშუალებას არ იძლევა."]],
        );
    }
}
