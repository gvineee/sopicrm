<?php

namespace App\Domain\Payroll\Support;

/**
 * REQ-PAY-12: defends against CSV/spreadsheet-formula injection (a real,
 * OWASP-documented vulnerability class — https://owasp.org/www-community/attacks/CSV_Injection).
 * A cell whose text starts with `=`, `+`, `-`, or `@` is interpreted as a
 * formula by Excel/Google Sheets/LibreOffice the moment the file is opened,
 * which lets a value that originated from free-text user input (an
 * employee's own name, an advance's `reason`, etc.) execute arbitrary
 * formula logic (including OS command execution via legacy Excel DDE, e.g.
 * `=cmd|'/c calc'!A1`) on whoever opens the export.
 *
 * Mitigation: prefix an offending value with a single quote `'`. Every
 * major spreadsheet application treats a leading `'` as "the rest of this
 * is literal text," so the formula-looking content is neutralized while
 * remaining human-readable (the leading quote itself is not usually shown
 * once opened). This is the standard, widely-documented mitigation for this
 * exact vulnerability class — do NOT remove/"simplify" this prefixing logic
 * without re-reading this docblock; a leading quote on an amount/date field
 * that looks unusual is deliberate, not a formatting bug.
 *
 * Only applied to columns that can ever originate from free-text user
 * input (names, reasons). A column this code itself computed (a decimal
 * amount, a date it formatted) is never passed through here — passing an
 * already-safe numeric string through `sanitize()` is harmless (it never
 * starts with one of the dangerous characters) but the call sites below
 * are explicit about which columns are genuinely user-controlled so a
 * future reader doesn't have to guess.
 */
final class CsvFormulaGuard
{
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public static function sanitize(?string $value): string
    {
        $value = $value ?? '';

        if ($value === '') {
            return $value;
        }

        foreach (self::DANGEROUS_PREFIXES as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return "'".$value;
            }
        }

        return $value;
    }
}
