<?php

namespace App\Domain\Payroll\Actions;

use App\Domain\Attendance\Models\Timesheet;
use App\Domain\Attendance\Models\TimesheetLine;
use App\Domain\Employees\Models\RateHistory;
use App\Domain\Payroll\DataTransferObjects\PayRunCalculationResult;
use App\Domain\Payroll\Exceptions\DailyPayPolicyNotConfirmedException;
use App\Domain\Payroll\Exceptions\InvalidPayRunStateException;
use App\Domain\Payroll\Models\DailyPayPolicy;
use App\Domain\Payroll\Models\PayAdjustment;
use App\Domain\Payroll\Models\PayRun;
use App\Domain\Payroll\Models\PayRunLine;
use App\Domain\Payroll\Services\DailyUnitCalculator;
use App\Domain\Payroll\Services\RateResolutionService;
use App\Domain\Payroll\Support\Money;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * spec section 8: builds/rebuilds a PayRun's lines from every APPROVED or
 * LOCKED Timesheet in its pay period. Deterministic and re-runnable while
 * the PayRun is still `draft`/`calculated` (mirrors the attendance-session
 * reconstruction pattern: same inputs -> same output, replaces rather than
 * accumulates) — never runs once a run has moved to `reviewed`/`approved`/
 * `locked` (spec: corrections after that point are reversal/adjustment
 * rows, never a silent recompute).
 *
 * Hourly: docs/data-model.md groups timesheet_lines by
 * (employee, project, rate_snapshot) — this Action sums payable_minutes per
 * group and computes `minutes / 60 * rate.amount`, half-up rounded once at
 * the line level (spec section 23 test row: 480 min x 15 GEL/hr = 120.00
 * GEL).
 *
 * Daily: groups by (employee, work_date) FIRST (spec section 8 hard rule:
 * one person working several sites in a day must not multiply the daily
 * rate), converts total minutes to a day-unit quantity via
 * DailyUnitCalculator against the org's confirmed DailyPayPolicy, caps it at
 * `max_day_units_per_work_date` unless a prior exception was already
 * granted on this exact PayRun (see GrantDailyCapExceptionAction — if any
 * line already carries a granted exception, recalculation is refused
 * outright rather than silently discarding it), then — for a multi-site day
 * — splits that day-unit total across the day's projects by minute-share
 * and the resulting MONEY total across projects using
 * Money::allocate (deterministic largest-remainder, spec section 8: "ჯამი
 * უცვლელი დარჩეს").
 */
class CalculatePayRunAction
{
    public function __construct(
        private readonly RateResolutionService $rates,
        private readonly DailyUnitCalculator $dailyUnits,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function execute(PayRun $payRun, User $actor): PayRunCalculationResult
    {
        if (! in_array($payRun->status, ['draft', 'calculated'], true)) {
            throw new InvalidPayRunStateException($payRun->status, 'draft or calculated', 'calculate');
        }

        if (PayAdjustment::query()->whereHas('payRunLine', fn ($q) => $q->where('pay_run_id', $payRun->id))->exists()) {
            throw new InvalidPayRunStateException(
                $payRun->status,
                'draft or calculated (no adjustments yet)',
                'recalculate — remove or reverse existing adjustments first'
            );
        }

        if (PayRunLine::query()->where('pay_run_id', $payRun->id)->whereNotNull('daily_cap_exception_approval_id')->exists()) {
            throw new InvalidPayRunStateException(
                $payRun->status,
                'draft or calculated (no granted daily-cap exceptions yet)',
                'recalculate — a daily-cap exception was already granted on this run and would be silently discarded'
            );
        }

        return DB::transaction(function () use ($payRun, $actor) {
            $before = $payRun->only(['status']);

            $payRun->lines()->delete();

            $period = $payRun->payPeriod;

            $timesheetIds = Timesheet::query()
                ->where('pay_period_id', $period->id)
                ->whereIn('status', ['approved', 'locked'])
                ->pluck('id');

            $lines = TimesheetLine::query()->whereIn('timesheet_id', $timesheetIds)->get();

            $warnings = [];
            $dailyPolicy = null;

            if ($lines->where('rate_type', 'daily')->isNotEmpty()) {
                $dailyPolicy = DailyPayPolicy::query()->first();

                if ($dailyPolicy === null || ! $dailyPolicy->is_confirmed) {
                    throw new DailyPayPolicyNotConfirmedException;
                }
            }

            $linesCreated = 0;
            $linesCreated += $this->buildHourlyLines($payRun, $lines->where('rate_type', 'hourly'));
            $linesCreated += $this->buildDailyLines($payRun, $lines->where('rate_type', 'daily'), $dailyPolicy, $warnings);

            $policySnapshot = [
                'rounding_rule' => Money::ROUNDING_RULE,
                'daily_pay_policy' => $dailyPolicy?->toSnapshot(),
                'calculated_from_timesheet_ids' => $timesheetIds->values()->all(),
            ];

            $payRun->status = 'calculated';
            $payRun->calculated_at = now();
            $payRun->calculated_by_user_id = $actor->id;
            $payRun->policy_version_snapshot = $policySnapshot;
            $payRun->save();

            $this->auditLogger->log(
                action: 'payroll.pay_run.calculated',
                target: $payRun,
                before: $before,
                after: $payRun->only(['status', 'calculated_at', 'calculated_by_user_id']),
                actor: $actor,
            );

            return new PayRunCalculationResult($payRun->refresh(), $linesCreated, $warnings);
        });
    }

    /**
     * @param  Collection<int, TimesheetLine>  $hourlyLines
     */
    private function buildHourlyLines(PayRun $payRun, Collection $hourlyLines): int
    {
        // TimesheetLine has no direct employee_id column (it hangs off its
        // Timesheet), so the grouping key is derived via that relation.
        $groups = $hourlyLines->groupBy(function (TimesheetLine $l) {
            return $l->timesheet->employee_id.'|'.$l->project_id.'|'.$l->rate_snapshot_id;
        });

        $count = 0;

        foreach ($groups as $groupLines) {
            /** @var TimesheetLine $first */
            $first = $groupLines->first();
            $employeeId = $first->timesheet->employee_id;
            $totalMinutes = (int) $groupLines->sum('payable_minutes');
            $rate = RateHistory::query()->findOrFail($first->rate_snapshot_id);

            $hours = bcdiv((string) $totalMinutes, '60', 6);
            $gross = Money::roundHalfUp(Money::mul($hours, (string) $rate->amount));

            PayRunLine::query()->create([
                'pay_run_id' => $payRun->id,
                'employee_id' => $employeeId,
                'project_id' => $first->project_id,
                'basis' => 'hourly',
                'quantity' => Money::roundHalfUp($hours, 2),
                'rate_snapshot_id' => $rate->id,
                'formula_applied' => "{$totalMinutes} წუთი / 60 × {$rate->amount} GEL = {$gross} GEL",
                'gross_amount' => $gross,
                'adjustments_amount' => 0,
                'net_amount' => $gross,
                'exceeds_daily_cap' => false,
            ]);

            $count++;
        }

        return $count;
    }

    /**
     * @param  Collection<int, TimesheetLine>  $dailyLines
     * @param  list<string>  $warnings
     */
    private function buildDailyLines(PayRun $payRun, Collection $dailyLines, ?DailyPayPolicy $policy, array &$warnings): int
    {
        if ($dailyLines->isEmpty()) {
            return 0;
        }

        if ($policy === null) {
            throw new DailyPayPolicyNotConfirmedException;
        }

        // Step 1: group by (employee, work_date) across ALL projects — the
        // hard rule this whole method exists to enforce.
        $byEmployeeDate = $dailyLines->groupBy(function (TimesheetLine $l) {
            return $l->timesheet->employee_id.'|'.$l->work_date->toDateString();
        });

        // Accumulator per (employee, project, rate_snapshot) across the
        // whole pay period, matching the hourly line shape.
        $accumulators = [];
        // key => bool, true if ANY contributing date exceeded the cap.
        $exceedsCap = [];

        foreach ($byEmployeeDate as $dayLines) {
            /** @var TimesheetLine $first */
            $first = $dayLines->first();
            $employeeId = $first->timesheet->employee_id;
            $workDate = $first->work_date->toDateString();
            $totalMinutes = (int) $dayLines->sum('payable_minutes');

            $unit = $this->dailyUnits->calculate($totalMinutes, $policy);

            if (! $unit->isPayable()) {
                $warnings[] = "{$employeeId}: {$workDate} — დღე არ ანაზღაურდა ({$unit->reason}, სულ {$totalMinutes} წთ).";

                continue;
            }

            $rawDayUnits = $unit->dayUnits;
            $capped = Money::min($rawDayUnits, (string) $policy->max_day_units_per_work_date);
            $exceeded = Money::compare($rawDayUnits, (string) $policy->max_day_units_per_work_date) > 0;

            if ($exceeded) {
                $warnings[] = "{$employeeId}: {$workDate} — დღის ერთეული ({$rawDayUnits}) აღემატება ნაგულისხმევ მაქსიმუმს და შეიზღუდა {$capped}-მდე; საჭიროებს ცალკე დამტკიცებას.";
            }

            $minutesByProject = $dayLines->groupBy('project_id')->map(fn ($g) => (int) $g->sum('payable_minutes'));

            if ($minutesByProject->count() === 1) {
                $projectKey = $minutesByProject->keys()->first();
                $projectId = $projectKey === null || $projectKey === '' ? null : (string) $projectKey;
                $rate = $this->rates->resolve($employeeId, $workDate, $projectId, 'daily');
                $rawMoney = Money::mul($capped, (string) $rate->amount);
                $gross = Money::roundHalfUp($rawMoney);

                $this->accumulate($accumulators, $exceedsCap, $employeeId, $projectId, $rate->id, $capped, $gross, $exceeded);

                continue;
            }

            // Multi-site day: split the day-unit total across projects by
            // minute-share, then split the resulting MONEY total across
            // those same projects deterministically so the day's total pay
            // never drifts from capped-day-units x each project's own rate
            // (spec section 8: "ჯამი უცვლელი დარჩეს").
            $projectIds = array_values(array_map(
                static fn (mixed $projectId): ?string => $projectId === ''
                    ? null
                    : (string) $projectId,
                $minutesByProject->keys()->values()->all(),
            ));
            $minuteWeights = array_values(array_map('strval', $minutesByProject->values()->all()));
            $dayUnitsByProject = Money::allocate($capped, $minuteWeights, 2);

            $rawMoneyByProject = [];
            $ratesByProject = [];

            foreach ($projectIds as $i => $projectId) {
                $rate = $this->rates->resolve($employeeId, $workDate, $projectId, 'daily');
                $ratesByProject[$i] = $rate;
                $rawMoneyByProject[] = Money::mul($dayUnitsByProject[$i], (string) $rate->amount);
            }

            $totalRawMoney = array_reduce($rawMoneyByProject, fn ($c, $v) => Money::add($c, $v), '0');
            $targetTotal = Money::roundHalfUp($totalRawMoney);
            $allocatedMoney = Money::allocate($targetTotal, $rawMoneyByProject, 2);

            foreach ($projectIds as $i => $projectId) {
                $this->accumulate(
                    $accumulators,
                    $exceedsCap,
                    $employeeId,
                    $projectId,
                    $ratesByProject[$i]->id,
                    $dayUnitsByProject[$i],
                    $allocatedMoney[$i],
                    $exceeded,
                );
            }
        }

        $count = 0;

        foreach ($accumulators as $key => $acc) {
            PayRunLine::query()->create([
                'pay_run_id' => $payRun->id,
                'employee_id' => $acc['employee_id'],
                'project_id' => $acc['project_id'],
                'basis' => 'daily',
                'quantity' => Money::roundHalfUp($acc['day_units'], 2),
                'rate_snapshot_id' => $acc['rate_snapshot_id'],
                'formula_applied' => "{$acc['day_units']} დღე × {$acc['rate_amount']} GEL = {$acc['gross']} GEL ({$acc['dates']} სამუშაო დღე)",
                'gross_amount' => $acc['gross'],
                'adjustments_amount' => 0,
                'net_amount' => $acc['gross'],
                'exceeds_daily_cap' => $exceedsCap[$key] ?? false,
            ]);

            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, array{employee_id: string, project_id: ?string, rate_snapshot_id: string, day_units: string, gross: string, rate_amount: string, dates: int}>  $accumulators
     * @param  array<string, bool>  $exceedsCap
     */
    private function accumulate(
        array &$accumulators,
        array &$exceedsCap,
        string $employeeId,
        ?string $projectId,
        string $rateSnapshotId,
        string $dayUnits,
        string $gross,
        bool $exceeded,
    ): void {
        $key = "{$employeeId}|{$projectId}|{$rateSnapshotId}";

        if (! isset($accumulators[$key])) {
            $rate = RateHistory::query()->findOrFail($rateSnapshotId);

            $accumulators[$key] = [
                'employee_id' => $employeeId,
                'project_id' => $projectId,
                'rate_snapshot_id' => $rateSnapshotId,
                'rate_amount' => (string) $rate->amount,
                'day_units' => '0',
                'gross' => '0',
                'dates' => 0,
            ];
            $exceedsCap[$key] = false;
        }

        $accumulators[$key]['day_units'] = Money::add($accumulators[$key]['day_units'], $dayUnits, 2);
        $accumulators[$key]['gross'] = Money::add($accumulators[$key]['gross'], $gross, 2);
        $accumulators[$key]['dates']++;
        $exceedsCap[$key] = $exceedsCap[$key] || $exceeded;
    }
}
