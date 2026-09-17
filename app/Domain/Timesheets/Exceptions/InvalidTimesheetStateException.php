<?php

namespace App\Domain\Timesheets\Exceptions;

/**
 * Raised by every state-transition Action when the timesheet is not in the
 * one state that transition is valid from (spec section 7: draft →
 * submitted → approved → locked; rejected → draft with a reason). Client
 * code can never force an arbitrary state directly (spec section 20:
 * "Client ვერ აგზავნის arbitrary approved=true-ს") — only these Actions'
 * named transitions exist.
 */
class InvalidTimesheetStateException extends TimesheetDomainException
{
    public function __construct(string $attemptedTransition, string $currentStatus, string $requiredStatus)
    {
        parent::__construct(
            "Cannot {$attemptedTransition} a timesheet in status [{$currentStatus}]; it must be [{$requiredStatus}].",
            'timesheet_invalid_state',
            409,
            ['status' => ["ტაბელის სტატუსი ({$currentStatus}) არ იძლევა ამ მოქმედების საშუალებას."]],
        );
    }
}
