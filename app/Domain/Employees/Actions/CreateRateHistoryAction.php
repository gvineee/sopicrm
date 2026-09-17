<?php

namespace App\Domain\Employees\Actions;

use App\Domain\Employees\Exceptions\OverlappingRateException;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\RateHistory;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * spec section 5: rate history rows (hourly/daily, amount, currency,
 * effective_from/to, optional project override, change reason, approver).
 * The DB's `rate_histories_no_overlap` GiST exclusion constraint (see the
 * Employees domain migration) is the real, race-proof guarantee against two
 * overlapping active rates at the same level; this Action additionally does
 * a fast, friendly pre-check so a normal (non-racing) form submission gets a
 * clear Georgian validation error instead of a raw DB constraint violation.
 */
class CreateRateHistoryAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{rate_type: string, amount: string|float, currency?: string, effective_from: string, effective_to?: string|null, project_id?: string|null, change_reason: string}  $data
     */
    public function execute(Employee $employee, array $data, User $approver): RateHistory
    {
        $projectId = $data['project_id'] ?? null;

        return DB::transaction(function () use ($employee, $data, $projectId, $approver) {
            $this->assertNoOverlap($employee->id, $projectId, $data['rate_type'], $data['effective_from'], $data['effective_to'] ?? null);

            try {
                $rate = RateHistory::query()->create([
                    'employee_id' => $employee->id,
                    'project_id' => $projectId,
                    'rate_type' => $data['rate_type'],
                    'amount' => $data['amount'],
                    'currency' => $data['currency'] ?? 'GEL',
                    'effective_from' => $data['effective_from'],
                    'effective_to' => $data['effective_to'] ?? null,
                    'change_reason' => $data['change_reason'],
                    'approved_by_user_id' => $approver->id,
                ]);
            } catch (QueryException $exception) {
                // Real guarantee against a race between two concurrent
                // submissions that both pass the pre-check above: the
                // Postgres exclusion constraint rejects the second insert.
                // sqlite (Pest's fast local test DB, per
                // docs/architecture.md §3.5/§3.6) has no equivalent
                // constraint, so this branch is exercised for real only
                // against Postgres — the pre-check above is what the sqlite
                // test suite actually verifies.
                if ($this->isExclusionViolation($exception)) {
                    throw OverlappingRateException::forPeriod($employee->id, $projectId, $data['rate_type']);
                }

                throw $exception;
            }

            $this->auditLogger->log(
                action: 'employees.rate_history.created',
                target: $rate,
                after: $rate->only(['employee_id', 'project_id', 'rate_type', 'amount', 'currency', 'effective_from', 'effective_to']),
                reason: $data['change_reason'],
                actor: $approver,
            );

            return $rate;
        });
    }

    private function assertNoOverlap(string $employeeId, ?string $projectId, string $rateType, string $effectiveFrom, ?string $effectiveTo): void
    {
        $overlaps = RateHistory::query()
            ->where('employee_id', $employeeId)
            ->where('rate_type', $rateType)
            ->when(
                $projectId === null,
                fn ($query) => $query->whereNull('project_id'),
                fn ($query) => $query->where('project_id', $projectId),
            )
            ->where(function ($query) use ($effectiveFrom, $effectiveTo) {
                // Two [from, to] ranges (either side possibly open-ended)
                // overlap iff each starts before (or when) the other ends.
                $query->where('effective_from', '<=', $effectiveTo ?? '9999-12-31')
                    ->where(function ($inner) use ($effectiveFrom) {
                        $inner->whereNull('effective_to')->orWhere('effective_to', '>=', $effectiveFrom);
                    });
            })
            ->exists();

        if ($overlaps) {
            throw OverlappingRateException::forPeriod($employeeId, $projectId, $rateType);
        }
    }

    private function isExclusionViolation(QueryException $exception): bool
    {
        // Postgres SQLSTATE 23P01 = exclusion_violation.
        return $exception->getCode() === '23P01'
            || str_contains($exception->getMessage(), 'rate_histories_no_overlap');
    }
}
