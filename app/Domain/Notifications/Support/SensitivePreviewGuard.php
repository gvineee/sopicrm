<?php

namespace App\Domain\Notifications\Support;

use RuntimeException;

/**
 * Hard constraint / spec section 17: "ხელფასის სრული თანხა ან ბარათის
 * ნომერი lock-screen ტექსტში არ გამოჩნდეს." A notification's `title`/
 * `message` is exactly the text an OS notification/lock-screen would show,
 * so it is the one place in this module where that constraint bites
 * directly. Every notification-creating call site in this module (Actions,
 * Observers) is expected to have already built human-safe, non-financial
 * text — this guard is the backstop that turns a slip into a loud failure
 * at write time instead of a leaked amount at read time, mirroring
 * resources/js/lib/offlineQueue.ts's `assertNotSensitive` on the frontend
 * half of the same constraint.
 */
final class SensitivePreviewGuard
{
    /**
     * A run of 12+ digits (optionally grouped) reads as a card/account
     * number; a decimal amount followed by a currency-looking token reads as
     * a full monetary sum. Both are refused in preview text — a
     * notification may reference "your card" or "a payment" by NAME, never
     * by number/amount.
     */
    private const CARD_LIKE_PATTERN = '/(?:\d[ -]?){12,19}/';

    private const MONEY_LIKE_PATTERN = '/\d[\d,.\s]*\s*(GEL|ლარი|₾|\$|USD|EUR)\b/iu';

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function assertSafe(array $payload): void
    {
        foreach (['title', 'message'] as $key) {
            $value = $payload[$key] ?? null;

            if (! is_string($value)) {
                continue;
            }

            if (preg_match(self::CARD_LIKE_PATTERN, $value) === 1) {
                throw new RuntimeException(
                    "Notification {$key} looks like it contains a full card/account number — refused (spec section 17)."
                );
            }

            if (preg_match(self::MONEY_LIKE_PATTERN, $value) === 1) {
                throw new RuntimeException(
                    "Notification {$key} looks like it contains a full monetary amount — refused (spec section 17)."
                );
            }
        }
    }
}
