<?php

namespace App\Domain\Notifications\Support;

/**
 * NOTIFY-01's exact read-only report list: "დასწრება, გამონაკლისები,
 * ვადაგადაცილებული სამუშაო, მისაღები დავალებები, BioStar იმპორტის
 * ჩამორჩენა და მხოლოდ უფლებამოსილი ფინანსური შეჯამება." Each type names
 * the permission App\Domain\Notifications\Actions\SendTelegramReportAction
 * re-checks immediately before sending — `FINANCIAL_SUMMARY` is checked
 * against the `access-financial-data` Gate instead (2FA-aware), never a
 * plain permission string, matching every other financial-data boundary in
 * this codebase.
 */
final class TelegramReportType
{
    public const ATTENDANCE_SUMMARY = 'attendance_summary';

    public const EXCEPTIONS = 'exceptions';

    public const OVERDUE_WORK = 'overdue_work';

    public const PENDING_ACCEPTANCE = 'pending_acceptance';

    public const BIOSTAR_IMPORT_LAG = 'biostar_import_lag';

    public const FINANCIAL_SUMMARY = 'financial_summary';

    /**
     * @return array<string, string> report type => required permission,
     *                               except FINANCIAL_SUMMARY which is
     *                               Gate-checked separately.
     */
    public static function requiredPermissions(): array
    {
        return [
            self::ATTENDANCE_SUMMARY => 'attendance.sessions.view',
            self::EXCEPTIONS => 'attendance.anomalies.view',
            self::OVERDUE_WORK => 'tasks.tasks.view',
            self::PENDING_ACCEPTANCE => 'tasks.tasks.view',
            self::BIOSTAR_IMPORT_LAG => 'devices.view',
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [...array_keys(self::requiredPermissions()), self::FINANCIAL_SUMMARY];
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }
}
