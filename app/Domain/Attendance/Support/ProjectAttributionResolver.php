<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Employees\Models\EmployeeProjectAssignment;
use Carbon\CarbonInterface;

/**
 * REQ-ATT-08: multi-site-per-day time attribution to the correct project.
 * `projects.site_id` exists ONLY for this resolution (see the docblock on
 * App\Domain\Projects\Models\Project). An AttendanceSession's project is
 * resolved from the employee's active EmployeeProjectAssignment(s) on
 * `work_date` whose project is sited at the session's site — never guessed:
 * zero or more-than-one match leaves `project_id` null for a human to set
 * explicitly via AttributeAttendanceSessionProjectAction.
 */
final class ProjectAttributionResolver
{
    public static function resolve(string $employeeId, string $siteId, CarbonInterface $workDate): ?string
    {
        $projectIds = EmployeeProjectAssignment::query()
            ->where('employee_id', $employeeId)
            ->where('starts_on', '<=', $workDate)
            ->where(function ($query) use ($workDate): void {
                $query->whereNull('ends_on')->orWhere('ends_on', '>=', $workDate);
            })
            ->whereHas('project', function ($query) use ($siteId): void {
                $query->where('site_id', $siteId);
            })
            ->pluck('project_id')
            ->unique();

        return $projectIds->count() === 1 ? $projectIds->first() : null;
    }
}
