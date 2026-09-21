<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Notifications\Models\TelegramLink;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * NOTIFY-01: user-initiated from their own profile/settings. Re-generating
 * a code for a user who already has a `TelegramLink` row updates that SAME
 * row (never accumulates a second one — the migration's own unique
 * constraint on (organization_id, user_id) would reject a second row
 * anyway) — a fresh code invalidates any previous unused one immediately.
 */
class GenerateTelegramLinkCodeAction
{
    public function execute(User $user): TelegramLink
    {
        $code = strtoupper(Str::random(8));

        return TelegramLink::query()->updateOrCreate(
            ['organization_id' => $user->organization_id, 'user_id' => $user->id],
            [
                'link_code' => $code,
                'link_code_expires_at' => now()->addMinutes(15),
                // Re-requesting a code before completing a previous link
                // must not silently keep an old, already-completed link
                // "half alive" — generating a new code always requires the
                // handshake to be completed again.
                'telegram_chat_id' => null,
                'linked_at' => null,
            ],
        );
    }
}
