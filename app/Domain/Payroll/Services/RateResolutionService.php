<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Employees\Models\RateHistory;
use App\Domain\Payroll\Exceptions\PayrollBlockedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * docs/data-model.md "rate_histories": "Rate resolution logic ... for a
 * given employee + work date + project, pick the project-level rate_history
 * row effective on that date if one exists, else the base (project_id IS
 * NULL) row effective on that date; if neither exists, block accrual for
 * that date ... rather than defaulting to zero or guessing." That model's
 * own docblock explicitly assigns this service to "the Payroll/Attendance
 * module agents" — this is Payroll's implementation.
 */
class RateResolutionService
{
    /**
     * @throws PayrollBlockedException when no rate resolves for this employee/date/project/type
     */
    public function resolve(string $employeeId, string $workDate, ?string $projectId, string $rateType): RateHistory
    {
        $date = Carbon::parse($workDate)->toDateString();

        if ($projectId !== null) {
            $projectRate = $this->query($employeeId, $projectId, $rateType, $date)->first();

            if ($projectRate !== null) {
                return $projectRate;
            }
        }

        $baseRate = $this->query($employeeId, null, $rateType, $date)->first();

        if ($baseRate !== null) {
            return $baseRate;
        }

        throw new PayrollBlockedException(
            $employeeId,
            $date,
            $projectId !== null
                ? "ვერც პროექტის და ვერც საბაზისო ტარიფი ({$rateType}) ვერ მოიძებნა."
                : "საბაზისო ტარიფი ({$rateType}) ვერ მოიძებნა."
        );
    }

    /**
     * @return Builder<RateHistory>
     */
    private function query(string $employeeId, ?string $projectId, string $rateType, string $date)
    {
        return RateHistory::query()
            ->where('employee_id', $employeeId)
            ->where('project_id', $projectId)
            ->where('rate_type', $rateType)
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from');
    }
}
