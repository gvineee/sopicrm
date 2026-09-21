<?php

namespace App\Domain\Payroll\Actions;

use App\Domain\Payroll\Models\DailyPayPolicy;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;

/**
 * One row per organization (App\Domain\Payroll\Models\DailyPayPolicy's own
 * docblock). Nothing else in this codebase creates or confirms this row —
 * without it, CalculatePayRunAction refuses every daily-basis PayRun via
 * DailyPayPolicyNotConfirmedException, by design (spec section 8: these
 * thresholds must be an explicit, accountant-confirmed decision, never a
 * silent code default).
 */
class ConfirmDailyPayPolicyAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{full_day_threshold_minutes: int, half_day_threshold_minutes: int, minimum_attendance_minutes: int, incomplete_day_behavior: string, max_day_units_per_work_date: string, notes?: string|null}  $data
     */
    public function execute(array $data, User $actor): DailyPayPolicy
    {
        $policy = DailyPayPolicy::query()->first() ?? new DailyPayPolicy;

        $before = $policy->exists ? $policy->only(['is_confirmed']) : null;

        $policy->fill([
            'full_day_threshold_minutes' => $data['full_day_threshold_minutes'],
            'half_day_threshold_minutes' => $data['half_day_threshold_minutes'],
            'minimum_attendance_minutes' => $data['minimum_attendance_minutes'],
            'incomplete_day_behavior' => $data['incomplete_day_behavior'],
            'max_day_units_per_work_date' => $data['max_day_units_per_work_date'],
            'notes' => $data['notes'] ?? null,
            'is_confirmed' => true,
            'confirmed_by_user_id' => $actor->id,
            'confirmed_at' => now(),
        ]);
        $policy->save();

        $this->auditLogger->log(
            action: 'payroll.daily_pay_policy.confirmed',
            target: $policy,
            before: $before,
            after: $policy->only(['is_confirmed', 'full_day_threshold_minutes', 'half_day_threshold_minutes', 'max_day_units_per_work_date']),
            actor: $actor,
        );

        return $policy->fresh();
    }
}
