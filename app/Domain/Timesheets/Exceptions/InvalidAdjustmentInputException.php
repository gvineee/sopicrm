<?php

namespace App\Domain\Timesheets\Exceptions;

/**
 * Raised when a manual correction request's input shape itself is
 * inconsistent — neither a corrected in/out pair nor corrected_hours given,
 * both given at once (ambiguous which is authoritative), or the supplied
 * work_date doesn't match the night-shift start-date attribution rule (spec
 * section 7: "ღამის ცვლა მიეკუთვნოს start-date-ს").
 */
class InvalidAdjustmentInputException extends TimesheetDomainException
{
    /**
     * @param  array<string, list<string>>  $fieldErrors
     */
    public static function make(string $message, array $fieldErrors): self
    {
        return new self($message, $fieldErrors);
    }

    /**
     * @param  array<string, list<string>>  $fieldErrors
     */
    private function __construct(string $message, array $fieldErrors)
    {
        // Routine bug fix (docs/decisions.md, Timesheets module pass): this
        // previously called parent::__construct($message, $fieldErrors),
        // passing the $fieldErrors array into the parent's string $errorCode
        // parameter position — a TypeError on every actual use. Corrected to
        // supply the errorCode/httpStatus explicitly.
        parent::__construct($message, 'attendance_adjustment_invalid_input', 422, $fieldErrors);
    }
}
