<?php

namespace App\Domain\Employees\Services;

use App\Domain\Employees\Exceptions\NoApplicableRateException;
use App\Domain\Employees\Models\RateHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * spec section 5 hard rule: "პრიორიტეტი: პროექტის ტარიფი → თანამშრომლის
 * ძირითადი ტარიფი; არარსებობისას დარიცხვა დაიბლოკოს" (priority: project rate
 * -> employee's base rate; block accrual when neither exists).
 *
 * This is a read-only resolution service — it never creates/mutates a
 * `rate_histories` row. It is the ONE place the priority rule is
 * implemented; the Payroll/Attendance modules (whichever module builds the
 * actual accrual Action) must call this rather than re-deriving the
 * priority logic themselves, per docs/data-model.md's own instruction
 * ("Rate resolution logic — Domain service, not duplicated in Vue").
 */
class RateResolutionService
{
    /**
     * Resolve the rate that applies for one employee on one work date,
     * optionally scoped to a project. Throws NoApplicableRateException
     * (never returns null / never defaults to zero) when no rate covers
     * that date at either level, per the hard rule above.
     */
    public function resolve(string $employeeId, string|Carbon $workDate, ?string $projectId, string $rateType): RateHistory
    {
        $date = $workDate instanceof Carbon ? $workDate->toDateString() : Carbon::parse($workDate)->toDateString();

        if ($projectId !== null) {
            $projectRate = $this->effectiveRateQuery($employeeId, $projectId, $rateType, $date)->first();

            if ($projectRate !== null) {
                return $projectRate;
            }
        }

        $baseRate = $this->effectiveRateQuery($employeeId, null, $rateType, $date)->first();

        if ($baseRate !== null) {
            return $baseRate;
        }

        throw NoApplicableRateException::forEmployeeOnDate($employeeId, $date, $projectId);
    }

    /**
     * Same as resolve(), but returns null instead of throwing — for UI
     * "what rate would apply right now" previews where blocking isn't the
     * right behavior (e.g. rendering a form). Real accrual paths must use
     * resolve(), never this.
     */
    public function tryResolve(string $employeeId, string|Carbon $workDate, ?string $projectId, string $rateType): ?RateHistory
    {
        try {
            return $this->resolve($employeeId, $workDate, $projectId, $rateType);
        } catch (NoApplicableRateException) {
            return null;
        }
    }

    /**
     * @return Builder<RateHistory>
     */
    private function effectiveRateQuery(string $employeeId, ?string $projectId, string $rateType, string $date)
    {
        return RateHistory::query()
            ->where('employee_id', $employeeId)
            ->where('rate_type', $rateType)
            ->when(
                $projectId === null,
                fn ($query) => $query->whereNull('project_id'),
                fn ($query) => $query->where('project_id', $projectId),
            )
            ->where('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderByDesc('effective_from');
    }
}
