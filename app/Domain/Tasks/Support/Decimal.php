<?php

namespace App\Domain\Tasks\Support;

/**
 * Quantities in this module travel as decimal strings and are compared with
 * `bc*`, never with floats: 0.1 + 0.2 quietly failing to equal 0.3 is not an
 * acceptable way to decide whether a task is finished.
 *
 * Eloquent's `decimal:2` cast, raw aggregates and request input all hand back
 * loosely-typed values, so this is the single gate they pass through before
 * reaching arithmetic — anything non-numeric (including a null sum over an
 * empty ledger) becomes "0.00" rather than a silent error deep in a
 * comparison.
 */
class Decimal
{
    /**
     * @return numeric-string
     */
    public static function of(mixed $value, int $scale = 2): string
    {
        $string = is_scalar($value) ? (string) $value : '0';

        if (! is_numeric($string)) {
            return bcadd('0', '0', $scale);
        }

        return bcadd($string, '0', $scale);
    }
}
