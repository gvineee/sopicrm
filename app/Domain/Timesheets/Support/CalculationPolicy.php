<?php

namespace App\Domain\Timesheets\Support;

/**
 * spec section 7: "დამტკიცებისას ინახება წყაროების ვერსია და გამოთვლის
 * პოლიტიკა" — a Timesheet's approval snapshot must record not just which
 * AttendanceSession rows/versions fed the calculation, but which *version of
 * the calculation rules themselves* was used, so a later change to how
 * minutes/rates/splits are computed can never silently reinterpret an
 * already-approved timesheet.
 *
 * This is a plain version tag (bumped whenever
 * App\Domain\Timesheets\Actions\BuildTimesheetLinesForSessionAction's
 * splitting/allocation algorithm changes in a way that could change output
 * for the same input), not a business-policy value — no rounding threshold,
 * break rule, or rate is hardcoded here (those remain ShiftTemplate/
 * RateHistory config, per the hard constraint against fabricating payroll
 * policy).
 */
final class CalculationPolicy
{
    public const CURRENT_VERSION = 'timesheets-calc-v1';

    /**
     * Human-readable description stored alongside the version tag in a
     * Timesheet's `source_sessions_version_snapshot`, so a reviewer can see
     * what the tag means without cross-referencing this class's history.
     */
    public const DESCRIPTION = 'Calendar-day split of AttendanceSession minutes across RateHistory boundaries; '
        .'project-override rate takes priority over employee base rate; no applicable rate blocks accrual.';
}
