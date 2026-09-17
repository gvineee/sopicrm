<?php

namespace App\Domain\Timesheets\Support;

use App\Domain\Employees\Models\RateHistory;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * spec section 5 hard rule: "პრიორიტეტი: პროექტის ტარიფი → თანამშრომლის
 * ძირითადი ტარიფი; არარსებობისას დარიცხვა დაიბლოკოს." A project-specific
 * RateHistory row (non-null `project_id`) effective on the given date always
 * wins over the employee's base rate (`project_id` null); if neither exists,
 * callers must block accrual (see
 * App\Domain\Timesheets\Exceptions\NoApplicableRateException) rather than
 * guessing a rate — this class only resolves, it never invents a default.
 */
final class RateResolver
{
    public function resolve(
        string $employeeId,
        ?string $projectId,
        CarbonInterface $onDate,
        string $rateType,
    ): ?RateHistory {
        $date = $onDate->toDateString();

        if ($projectId !== null) {
            $projectRate = $this->effectiveRateQuery($employeeId, $rateType, $date)
                ->where('project_id', $projectId)
                ->first();

            if ($projectRate !== null) {
                return $projectRate;
            }
        }

        return $this->effectiveRateQuery($employeeId, $rateType, $date)
            ->whereNull('project_id')
            ->first();
    }

    /**
     * @return Builder<RateHistory>
     */
    private function effectiveRateQuery(string $employeeId, string $rateType, string $date)
    {
        return RateHistory::query()
            ->where('employee_id', $employeeId)
            ->where('rate_type', $rateType)
            ->where('effective_from', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from');
    }
}
