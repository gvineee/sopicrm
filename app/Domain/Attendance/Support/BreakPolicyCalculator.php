<?php

namespace App\Domain\Attendance\Support;

use Illuminate\Support\Carbon;

/**
 * Pure calculation, no Eloquent/DB access — unit-testable without booting
 * the framework (spec section 23: "Unit tests: დრო/ტარიფი/ფულის
 * გამოთვლა").
 *
 * Spec section 7: a shift's break may be `fixed` (a flat number of minutes)
 * or `scheduled` (specific clock windows), but the SAME break window is
 * never deducted twice, and raw clock-in/out time itself is never rounded —
 * only the deducted minute count is computed, to the exact minute.
 *
 * `docs/data-model.md` "shift_templates.break_policy" shape:
 *   {"type":"fixed","minutes":60}
 *   {"type":"scheduled","windows":[{"key":"lunch","start":"13:00","end":"13:30"}]}
 */
final class BreakPolicyCalculator
{
    /**
     * @param  array<string, mixed>  $breakPolicy
     * @return list<array{key: string, minutes: int}> one entry per break
     *                                                window actually
     *                                                deducted for this
     *                                                session — the caller
     *                                                persists these against
     *                                                `attendance_session_break_deductions`'
     *                                                unique
     *                                                (session,template,window)
     *                                                triple so a recompute
     *                                                can never double-apply
     *                                                one.
     */
    public static function deductions(array $breakPolicy, Carbon $clockIn, Carbon $clockOut): array
    {
        $type = $breakPolicy['type'] ?? 'fixed';

        if ($type === 'scheduled') {
            /** @var list<array{key?: string, start: string, end: string}> $windows */
            $windows = $breakPolicy['windows'] ?? [];

            return self::scheduledDeductions($windows, $clockIn, $clockOut);
        }

        $minutes = (int) ($breakPolicy['minutes'] ?? 0);

        if ($minutes <= 0) {
            return [];
        }

        $sessionMinutes = self::exactMinutes($clockIn, $clockOut);

        // Never deduct more break than the session actually spans — a
        // session shorter than the configured fixed break loses, at most,
        // its own length (never goes negative).
        $applied = min($minutes, $sessionMinutes);

        if ($applied <= 0) {
            return [];
        }

        return [['key' => 'fixed', 'minutes' => $applied]];
    }

    /**
     * @param  list<array{key?: string, start: string, end: string}>  $windows
     * @return list<array{key: string, minutes: int}>
     */
    private static function scheduledDeductions(array $windows, Carbon $clockIn, Carbon $clockOut): array
    {
        $deductions = [];

        foreach ($windows as $window) {
            $key = $window['key'] ?? sprintf('%s-%s', $window['start'], $window['end']);

            $windowStart = Carbon::parse($clockIn->format('Y-m-d').' '.$window['start'], $clockIn->getTimezone());
            $windowEnd = Carbon::parse($clockIn->format('Y-m-d').' '.$window['end'], $clockIn->getTimezone());

            // A window whose time-of-day is earlier than the clock-in
            // time-of-day means the *intended* occurrence is the following
            // calendar day (e.g. a 22:00 night shift with a "02:00-02:30"
            // break) — never the one that already passed before the shift
            // even started.
            if ($windowStart->lessThan($clockIn)) {
                $windowStart->addDay();
                $windowEnd->addDay();
            }

            // The window itself crosses midnight (e.g. "23:30-00:30").
            if ($windowEnd->lessThanOrEqualTo($windowStart)) {
                $windowEnd->addDay();
            }

            $overlapStart = $clockIn->greaterThan($windowStart) ? $clockIn->copy() : $windowStart;
            $overlapEnd = $clockOut->lessThan($windowEnd) ? $clockOut->copy() : $windowEnd;

            if ($overlapEnd->greaterThan($overlapStart)) {
                $deductions[] = ['key' => (string) $key, 'minutes' => self::exactMinutes($overlapStart, $overlapEnd)];
            }
        }

        return $deductions;
    }

    /**
     * Exact whole minutes between two instants, floored (never rounded up,
     * never invented) — spec section 7: "Raw დრო არასდროს დამრგვალდეს."
     */
    public static function exactMinutes(Carbon $from, Carbon $to): int
    {
        return intdiv(max(0, $to->getTimestamp() - $from->getTimestamp()), 60);
    }
}
