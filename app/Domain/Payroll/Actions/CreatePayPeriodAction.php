<?php

namespace App\Domain\Payroll\Actions;

use App\Domain\Payroll\Models\PayPeriod;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Nothing else in this codebase creates a `pay_periods` row — Timesheets'
 * `timesheets.pay_period_id` FK and the Attendance→Timesheet→PayRun chain
 * are all otherwise inert without one. Overlap is blocked as a defensive
 * business rule (not explicitly stated in spec, but paying the same
 * calendar day twice via two overlapping periods is a real bug magnet) —
 * the DB's own `unique(organization_id, starts_on, ends_on)` only rejects
 * an exact-duplicate range, not a partially-overlapping one.
 */
class CreatePayPeriodAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(string $startsOn, string $endsOn, User $actor): PayPeriod
    {
        if (Carbon::parse($endsOn)->lt(Carbon::parse($startsOn))) {
            throw new InvalidArgumentException('ends_on must not be before starts_on.');
        }

        $overlaps = PayPeriod::query()
            ->where('starts_on', '<=', $endsOn)
            ->where('ends_on', '>=', $startsOn)
            ->exists();

        if ($overlaps) {
            throw new InvalidArgumentException('This period overlaps an existing pay period.');
        }

        $period = PayPeriod::query()->create([
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'status' => 'open',
        ]);

        $this->auditLogger->log(
            action: 'payroll.pay_period.created',
            target: $period,
            after: $period->only(['starts_on', 'ends_on']),
            actor: $actor,
        );

        return $period;
    }
}
