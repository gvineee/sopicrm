<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Notifications\Exceptions\TelegramLinkCodeInvalidException;
use App\Domain\Notifications\Models\TelegramLink;
use App\Domain\Shared\Services\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/**
 * NOTIFY-01: models the "bot" side of the one-time-code handshake — in a
 * real deployment, a Telegram webhook receiving `/start <code>` from the
 * bot would call this with the code and the chat id it observed, with NO
 * authenticated CRM session and therefore no tenant context established yet
 * (the code is exactly what identifies which organization/user it belongs
 * to). No real webhook exists in this codebase yet (mocked transport only);
 * this Action is the real, testable linking logic a future webhook
 * controller would call unchanged.
 *
 * `link_code` is deliberately globally unique (see the migration's own
 * docblock), so the initial lookup uses `withoutTenantScope()` — the same
 * pre-tenant-context problem App\Domain\Shared\Services\AuditLogger already
 * solves for its own cross-tenant write, copied here: once the row's real
 * organization_id is known, `app.current_org_id` is set explicitly so the
 * table's normal RLS policy (unchanged, no new escape-hatch policy needed)
 * allows the UPDATE, then restored to whatever it was before this call.
 */
class CompleteTelegramLinkAction
{
    public function execute(string $code, string $chatId): TelegramLink
    {
        /** @var TelegramLink|null $link */
        $link = TelegramLink::withoutTenantScope()->where('link_code', $code)->first();

        if ($link === null || ! $link->isCodeUsable()) {
            throw new TelegramLinkCodeInvalidException('This linking code is invalid or has expired.');
        }

        $previousOrganizationId = CurrentOrganization::id();
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';

        try {
            CurrentOrganization::set($link->organization_id);

            if ($isPgsql) {
                DB::statement("select set_config('app.current_org_id', ?, false)", [$link->organization_id]);
            }

            $link->update(['telegram_chat_id' => $chatId, 'linked_at' => now()]);

            return $link->fresh();
        } finally {
            CurrentOrganization::set($previousOrganizationId);

            if ($isPgsql) {
                DB::statement(
                    "select set_config('app.current_org_id', ?, false)",
                    [$previousOrganizationId ?? '']
                );
            }
        }
    }
}
