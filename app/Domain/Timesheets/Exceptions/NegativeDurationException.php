<?php

namespace App\Domain\Timesheets\Exceptions;

/**
 * spec section 7 hard rule: "უარყოფითი ხანგრძლივობა დაიბლოკოს." Mirrors the
 * DB check constraint `attendance_adjustments_positive_duration`, raised
 * pre-save for a fast, friendly error (the DB constraint remains the real
 * guarantee, per the same pattern already established for rate_histories'
 * overlap exclusion constraint in docs/data-model.md).
 */
class NegativeDurationException extends TimesheetDomainException
{
    public function __construct()
    {
        parent::__construct(
            'Corrected clock-out must be strictly after corrected clock-in.',
            'attendance_adjustment_negative_duration',
            422,
            ['corrected_clock_out_at' => ['გასვლის დრო უნდა იყოს შესვლის დროის შემდეგ.']],
        );
    }
}
