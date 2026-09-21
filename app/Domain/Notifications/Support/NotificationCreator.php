<?php

namespace App\Domain\Notifications\Support;

use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Shared\Models\Notification;
use App\Models\User;

/**
 * NOTIFY-01: the single call path every real trigger site
 * (AssignTask, ReturnTaskSubmission, AddComment, the overdue/tool-due
 * scheduled command, device heartbeat, attendance anomaly flagging) goes
 * through — always checks the recipient's own mute preference and always
 * runs the payload through SensitivePreviewGuard before the row is
 * written, so no call site can accidentally skip either check.
 */
class NotificationCreator
{
    public static function create(
        User $recipient,
        string $type,
        string $title,
        string $message,
        string $dedupKey,
        ?string $deepLink = null,
    ): ?Notification {
        $preference = NotificationPreference::query()
            ->where('organization_id', $recipient->organization_id)
            ->where('user_id', $recipient->id)
            ->first();

        if ($preference?->hasMuted($type)) {
            return null;
        }

        $payload = ['title' => $title, 'message' => $message];
        SensitivePreviewGuard::assertSafe($payload);

        return Notification::query()->firstOrCreate(
            [
                'organization_id' => $recipient->organization_id,
                'recipient_user_id' => $recipient->id,
                'dedup_key' => $dedupKey,
            ],
            [
                'type' => $type,
                'payload' => $payload,
                'deep_link' => $deepLink,
            ],
        );
    }
}
