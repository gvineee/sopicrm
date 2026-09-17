<?php

namespace App\Domain\Payroll\Support;

use InvalidArgumentException;

/**
 * Exact-decimal money/quantity arithmetic for the Payroll module, backed by
 * PHP's bcmath extension (confirmed present in every environment this app
 * has been built in — see docs/decisions.md) rather than native float. This
 * is the Payroll-owned implementation of docs/architecture.md §5's money
 * rule ("Postgres numeric, ... never float ... half-up to 0.01 ...
 * deterministic remainder allocation") — no shared `brick/money`-backed cast
 * exists yet anywhere in this codebase (only plain Eloquent `decimal:2`
 * casts, which store/return PHP strings, never floats), so this class is
 * the one place Payroll does decimal math on those string values. See
 * docs/decisions.md for why this was built here rather than assumed to
 * exist under app/Support.
 *
 * Every public method takes and returns decimal STRINGS (never float) so a
 * caller can never accidentally round-trip a value through float precision
 * loss by passing an `(float)` cast.
 */
final class Money
{
    /**
     * The only rounding rule this module uses for a final GEL line amount
     * (spec section 8: "GEL საბოლოო რიგი დამრგვალდეს 0.01-მდე
     * დოკუმენტირებული half-up წესით"). Documented here as the single named
     * constant every PayRun/PayRunLine snapshot references, so "which
     * rounding rule was used" is never ambiguous after the fact.
     */
    public const ROUNDING_RULE = 'half_up_0.01_gel';

    private const INTERNAL_SCALE = 12;

    public static function add(string $a, string $b, int $scale = self::INTERNAL_SCALE): string
    {
        self::guardNumeric($a);
        self::guardNumeric($b);

        return bcadd($a, $b, $scale);
    }

    public static function sub(string $a, string $b, int $scale = self::INTERNAL_SCALE): string
    {
        self::guardNumeric($a);
        self::guardNumeric($b);

        return bcsub($a, $b, $scale);
    }

    public static function mul(string $a, string $b, int $scale = self::INTERNAL_SCALE): string
    {
        self::guardNumeric($a);
        self::guardNumeric($b);

        return bcmul($a, $b, $scale);
    }

    public static function div(string $a, string $b, int $scale = self::INTERNAL_SCALE): string
    {
        self::guardNumeric($a);
        self::guardNumeric($b);

        if (bccomp($b, '0', $scale) === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return bcdiv($a, $b, $scale);
    }

    /**
     * -1, 0, or 1 — bccomp with a fixed, generous scale so "0.10" and
     * "0.1000" compare equal.
     */
    public static function compare(string $a, string $b): int
    {
        self::guardNumeric($a);
        self::guardNumeric($b);

        return bccomp($a, $b, self::INTERNAL_SCALE);
    }

    public static function isZero(string $a): bool
    {
        return self::compare($a, '0') === 0;
    }

    public static function isNegative(string $a): bool
    {
        return self::compare($a, '0') < 0;
    }

    public static function max(string $a, string $b): string
    {
        return self::compare($a, $b) >= 0 ? $a : $b;
    }

    public static function min(string $a, string $b): string
    {
        return self::compare($a, $b) <= 0 ? $a : $b;
    }

    /**
     * Round-half-up (round-half-away-from-zero for a negative value, since
     * pay_adjustments.amount is signed — a deduction of "-10.005" rounds to
     * "-10.01", not toward zero) to the given scale. Documented as
     * `Money::ROUNDING_RULE` wherever a PayRun/PayRunLine snapshot records
     * which rounding rule produced its amounts.
     */
    public static function roundHalfUp(string $value, int $scale = 2): string
    {
        self::guardNumeric($value);

        $negative = self::isNegative($value);
        $abs = $negative ? bcmul($value, '-1', self::INTERNAL_SCALE) : $value;

        $factor = bcpow('10', (string) $scale);
        $shifted = bcmul($abs, $factor, self::INTERNAL_SCALE);
        $roundedUnits = bcadd($shifted, '0.5', 0); // bcadd(..., 0) truncates toward zero, i.e. floors a non-negative value.
        $result = bcdiv($roundedUnits, $factor, $scale);

        return $negative && ! self::isZero($result) ? bcmul($result, '-1', $scale) : $result;
    }

    /**
     * Deterministic largest-remainder allocation: splits `$total` across
     * `$weights` (non-negative, need not sum to anything in particular) so
     * that every returned share is rounded to `$scale` AND the shares sum
     * EXACTLY to `$total` — spec section 8: "პროექტებზე გაყოფის ნაშთი
     * მიენიჭოს დეტერმინისტულად ისე, რომ ჯამი უცვლელი დარჩეს."
     *
     * Algorithm: give each weight its proportional floor share, then hand
     * the leftover smallest units (1 = 10^-scale each) to the shares with
     * the largest fractional remainder, largest first; ties break on
     * original input order (index), so re-running against the same inputs
     * always produces the same allocation.
     *
     * @param  list<string>  $weights
     * @return list<string> same length/order as $weights, summing exactly to $total
     */
    public static function allocate(string $total, array $weights, int $scale = 2): array
    {
        self::guardNumeric($total);

        foreach ($weights as $weight) {
            self::guardNumeric($weight);
        }

        $count = count($weights);

        if ($count === 0) {
            if (! self::isZero($total)) {
                throw new InvalidArgumentException('Cannot allocate a non-zero total across zero weights.');
            }

            return [];
        }

        $weightSum = array_reduce($weights, fn (string $carry, string $w): string => self::add($carry, $w), '0');

        if (self::isZero($weightSum)) {
            // No basis to weight by (e.g. every project had 0 minutes that
            // day) — split evenly rather than divide by zero, still summing
            // exactly to $total via the same remainder mechanism below.
            $weights = array_fill(0, $count, '1');
            $weightSum = (string) $count;
        }

        // Every real caller (money split across projects, day-units split
        // across sites) passes non-negative weights and a non-negative
        // total; floor-toward-zero (`bcadd($units, '0', 0)`, bcmath's
        // truncating scale-0 add) is therefore equivalent to a true floor.
        $unit = bcdiv('1', bcpow('10', (string) $scale), self::INTERNAL_SCALE);

        $floors = [];
        $remainders = [];
        $allocatedTotal = '0';

        foreach ($weights as $i => $weight) {
            $exactShare = self::div(self::mul($total, $weight), $weightSum);
            $flooredUnits = bcadd(bcdiv($exactShare, $unit, self::INTERNAL_SCALE), '0', 0);
            $floorShare = bcmul($flooredUnits, $unit, $scale);

            $floors[$i] = $floorShare;
            $remainders[$i] = self::sub($exactShare, $floorShare);
            $allocatedTotal = self::add($allocatedTotal, $floorShare);
        }

        // Round-half-up the leftover-unit count itself, so tiny bcmath
        // internal-scale artifacts (e.g. "1.999999999999" instead of "2")
        // never cause an off-by-one in how many leftover cents get handed
        // out below.
        $rawLeftoverUnits = self::div(self::sub($total, $allocatedTotal), $unit, self::INTERNAL_SCALE);
        self::guardNumeric($rawLeftoverUnits);
        $leftoverUnits = (int) bcadd($rawLeftoverUnits, '0.5', 0);

        // Largest fractional remainder first; index as a deterministic
        // tie-breaker so identical inputs always distribute identically.
        $order = array_keys($remainders);
        usort($order, function (int $a, int $b) use ($remainders): int {
            $cmp = self::compare($remainders[$b], $remainders[$a]);

            return $cmp !== 0 ? $cmp : $a <=> $b;
        });

        $shares = $floors;
        for ($i = 0; $i < $leftoverUnits && $i < count($order); $i++) {
            $shares[$order[$i]] = self::add($shares[$order[$i]], $unit, $scale);
        }

        return array_values($shares);
    }

    /**
     * @phpstan-assert numeric-string $value
     */
    private static function guardNumeric(string $value): void
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("[{$value}] is not a valid decimal number.");
        }
    }
}
