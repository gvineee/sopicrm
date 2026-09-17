<?php

namespace App\Domain\DailyJournal\Exceptions;

/**
 * Thrown when a workflow action (submit/accept/return/edit-draft) is
 * attempted from a status it isn't valid from — e.g. accepting a `draft`
 * report, or editing-as-draft an already-`accepted` one without going
 * through the explicit revision path.
 */
class InvalidDailyReportStateException extends DailyReportDomainException
{
    public function __construct(string $action, string $currentStatus)
    {
        parent::__construct(
            "მოქმედება „{$action}“ დაუშვებელია სტატუსიდან „{$currentStatus}“."
        );
    }
}
