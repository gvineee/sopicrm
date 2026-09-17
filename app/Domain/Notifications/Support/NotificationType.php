<?php

namespace App\Domain\Notifications\Support;

/**
 * Plain string constants, not a backed PHP enum — matches the project-wide
 * convention (docs/decisions.md DEC-066: status/type columns stay plain
 * strings validated by a DB CHECK/application check, not a backed enum
 * class) and matches the exact type strings already named in
 * docs/data-model.md's "notifications" entry.
 *
 * This is the complete spec section 17 P1 list: "in-app notifications
 * დავალების მინიჭებაზე, დაბრუნებაზე, mention-ზე, ვადაგადაცილებაზე,
 * ხელსაწყოს ვადაზე, ტაბელის გამონაკლისზე და მოწყობილობის ხარვეზზე."
 */
final class NotificationType
{
    public const TASK_ASSIGNED = 'task_assigned';

    public const TASK_RETURNED = 'task_returned';

    public const MENTION = 'mention';

    public const TASK_OVERDUE = 'overdue';

    public const TOOL_RETURN_DUE = 'tool_return_due';

    public const TIMESHEET_EXCEPTION = 'timesheet_exception';

    public const DEVICE_FAULT = 'device_fault';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::TASK_ASSIGNED,
            self::TASK_RETURNED,
            self::MENTION,
            self::TASK_OVERDUE,
            self::TOOL_RETURN_DUE,
            self::TIMESHEET_EXCEPTION,
            self::DEVICE_FAULT,
        ];
    }

    /**
     * Georgian display labels for the preferences screen (spec section 4:
     * UI-facing text is Georgian).
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::TASK_ASSIGNED => 'დავალების მინიჭება',
            self::TASK_RETURNED => 'დავალების დაბრუნება',
            self::MENTION => 'ხსენება კომენტარში',
            self::TASK_OVERDUE => 'დავალების ვადაგადაცილება',
            self::TOOL_RETURN_DUE => 'ხელსაწყოს დაბრუნების ვადა',
            self::TIMESHEET_EXCEPTION => 'ტაბელის გამონაკლისი',
            self::DEVICE_FAULT => 'მოწყობილობის ხარვეზი',
        ];
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }
}
