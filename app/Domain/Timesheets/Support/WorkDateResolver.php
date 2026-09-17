<?php

namespace App\Domain\Timesheets\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * spec section 7: "ღამის ცვლა მიეკუთვნოს start-date-ს ტაბელში" — an
 * overnight shift's whole session is attributed, in the timesheet, to the
 * calendar date its clock-in happened on (site-local time), never split
 * across two `work_date`s even though the underlying minutes physically
 * span two calendar days (that physical split is a *rate-allocation*
 * concern — see BuildTimesheetLinesForSessionAction — not a `work_date`
 * concern).
 */
final class WorkDateResolver
{
    /**
     * @param  string  $timezone  Site/organization-configured timezone
     *                            (spec/architecture default: Asia/Tbilisi).
     *                            Never derived ad hoc from a bare UTC
     *                            timestamp without an explicit zone.
     */
    public static function startDateFor(CarbonInterface $clockInAt, string $timezone = 'Asia/Tbilisi'): CarbonImmutable
    {
        return CarbonImmutable::instance($clockInAt)->setTimezone($timezone)->startOfDay();
    }
}
