<?php

namespace App\Domain\Timesheets\Support;

use InvalidArgumentException;

/**
 * Decimal-exact money/quantity arithmetic for this module, backed by
 * PHP's bcmath extension — never native float/double (hard constraint:
 * "All money uses exact decimal types, never float"). This mirrors the
 * precedent already set across the codebase's Eloquent models (plain
 * `decimal:2` casts, e.g. App\Domain\Payroll\Models\PayRunLine), since
 * `brick/money` (DEC-011's originally anticipated package) was never
 * actually installed by any prior pass — see docs/decisions.md's
 * Timesheets-module entry, which records this as the routine decision to
 * follow existing precedent rather than add a new dependency for it.
 *
 * bcmath ships with PHP by default in this environment (confirmed present),
 * so this needs no new Composer dependency.
 */
final class MoneyMath
{
    /**
     * Half-up rounding to a fixed number of decimals, implemented via the
     * standard bcmath idiom (add/subtract a half-unit at the target scale,
     * then let bcadd/bcsub's truncating scale do the rounding) rather than
     * PHP 8.4's native `bcround()`, since this project's composer.json
     * targets `"php": "^8.3"` and must not assume a PHP-8.4-only function.
     */
    public static function roundHalfUp(string $value, int $decimals = 2): string
    {
        self::guardNumeric($value);
        $isNegative = str_starts_with($value, '-');
        $half = '0.'.str_repeat('0', $decimals).'5';
        self::guardNumeric($half);

        return $isNegative
            ? bcsub($value, $half, $decimals)
            : bcadd($value, $half, $decimals);
    }

    public static function add(string $a, string $b, int $scale = 6): string
    {
        self::guardNumeric($a);
        self::guardNumeric($b);

        return bcadd($a, $b, $scale);
    }

    public static function multiply(string $a, string $b, int $scale = 6): string
    {
        self::guardNumeric($a);
        self::guardNumeric($b);

        return bcmul($a, $b, $scale);
    }

    public static function divide(string $a, string $b, int $scale = 6): string
    {
        self::guardNumeric($a);
        self::guardNumeric($b);

        if (bccomp($b, '0', $scale) === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return bcdiv($a, $b, $scale);
    }

    public static function isPositive(string $value): bool
    {
        self::guardNumeric($value);

        return bccomp($value, '0', 6) > 0;
    }

    public static function isNegativeOrZero(string $value): bool
    {
        self::guardNumeric($value);

        return bccomp($value, '0', 6) <= 0;
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
