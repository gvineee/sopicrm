<?php

namespace App\Console\Commands;

use App\Domain\Auth\Actions\GetOrCreateSystemActorAction;
use App\Domain\Auth\Models\Organization;
use App\Domain\Notifications\Actions\SendTelegramReportAction;
use App\Domain\Notifications\Exceptions\TelegramReportNotAuthorizedException;
use App\Domain\Notifications\Models\TelegramLink;
use App\Domain\Notifications\Models\TelegramReportDelivery;
use App\Domain\Notifications\Support\TelegramReportType;
use App\Domain\Shared\Services\CurrentOrganization;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * NOTIFY-01 (deferred remainder, docs/agent-handoff.md's own recorded next
 * step): the scheduled half of Telegram reporting — the manual "send me a
 * report now" flow (App\Http\Controllers\TelegramLinkController) already
 * existed; this is what makes it a real daily digest instead of something
 * a user has to remember to request.
 *
 * A "digest" here means: for every ACTUALLY linked TelegramLink
 * (`linked_at` not null — a pending, unconsumed code is never sent to),
 * attempt every App\Domain\Notifications\Support\TelegramReportType, once
 * per calendar day, and let App\Domain\Notifications\Actions\
 * SendTelegramReportAction's OWN existing per-type authorization check
 * (already re-derived at send time, already 2FA-aware for the financial
 * summary) decide which of those actually go out — never a second,
 * possibly-drifting authorization check duplicated here. This deliberately
 * reuses that Action's existing TelegramReportDelivery history/retry
 * mechanism rather than inventing a second one: each report type that goes
 * through is its own delivery row, exactly like a manual request would
 * produce, just triggered on a schedule instead of a click. A user with no
 * authorized report types that day (e.g. never granted any of the
 * underlying permissions) simply gets zero messages and zero delivery
 * rows — this command creates nothing on their behalf beyond what
 * SendTelegramReportAction itself would already create.
 *
 * Dedup is per (telegram_link_id, report_type, calendar day) — a link
 * still linked and re-scanned on every run of this command only ever gets
 * ONE delivery attempt per type per day, checked against
 * `telegram_report_deliveries.created_at`'s own date (that table has no
 * separate dedup_key column, unlike the in-app `notifications` table, so
 * the check is a direct date-range query rather than a unique key).
 *
 * Iterates every organization with the same per-org tenant-context
 * set/restore discipline `attendance:process-incremental`/
 * `devices:health-check` already established this session — never a
 * cross-tenant query, one organization's own rows at a time.
 */
class TelegramSendDigest extends Command
{
    protected $signature = 'telegram:send-digest';

    protected $description = 'Send each linked Telegram user their daily digest of authorized read-only reports.';

    public function handle(
        GetOrCreateSystemActorAction $getOrCreateSystemActor,
        SendTelegramReportAction $sendReport,
    ): int {
        $previousOrganizationId = CurrentOrganization::id();
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';
        $sent = 0;

        try {
            $organizationIds = Organization::query()->pluck('id');

            foreach ($organizationIds as $organizationId) {
                $this->setOrganizationContext($organizationId, $isPgsql);

                $sent += $this->digestOrganization($organizationId, $getOrCreateSystemActor, $sendReport);
            }
        } finally {
            $this->setOrganizationContext($previousOrganizationId, $isPgsql);
        }

        $this->info("Sent {$sent} Telegram digest message(s).");

        return self::SUCCESS;
    }

    private function digestOrganization(
        string $organizationId,
        GetOrCreateSystemActorAction $getOrCreateSystemActor,
        SendTelegramReportAction $sendReport,
    ): int {
        $links = TelegramLink::query()
            ->where('organization_id', $organizationId)
            ->whereNotNull('linked_at')
            ->get();

        if ($links->isEmpty()) {
            return 0;
        }

        $today = Carbon::now()->toDateString();
        $systemActor = null;
        $sent = 0;

        foreach ($links as $link) {
            foreach (TelegramReportType::all() as $reportType) {
                $alreadySentToday = TelegramReportDelivery::query()
                    ->where('telegram_link_id', $link->id)
                    ->where('report_type', $reportType)
                    ->whereDate('created_at', $today)
                    ->exists();

                if ($alreadySentToday) {
                    continue;
                }

                $systemActor ??= $getOrCreateSystemActor->execute($organizationId);

                try {
                    $delivery = $sendReport->execute($link, $reportType, $systemActor);

                    if ($delivery->status === 'sent') {
                        $sent++;
                    }
                } catch (TelegramReportNotAuthorizedException) {
                    // The linked user isn't currently entitled to this
                    // report type — a normal, expected outcome for most
                    // users on most types, not a failure. No delivery row
                    // is created for a denied check (matches
                    // SendTelegramReportAction's own existing behavior).
                    continue;
                }
            }
        }

        return $sent;
    }

    /**
     * Sets BOTH tenant-context mechanisms this codebase uses — the plain
     * `CurrentOrganization`/Postgres GUC pair every other scheduled command
     * this session already sets, AND spatie/laravel-permission's own
     * "team id" (normally set per-request by
     * App\Http\Middleware\SetCurrentOrganization, which a console command
     * never runs through). Missing this second one is a real bug this
     * command's own multi-organization test caught directly: without it,
     * `SendTelegramReportAction`'s `$recipient->can(...)`/`Gate::forUser(...)`
     * checks resolve against whichever organization's team id was set LAST
     * across the whole process, not the organization actually being
     * processed on this iteration — a false-negative permission denial (or
     * worse, a false-positive) for every organization except the last one
     * in a multi-tenant run.
     */
    private function setOrganizationContext(?string $organizationId, bool $isPgsql): void
    {
        CurrentOrganization::set($organizationId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($organizationId);

        if ($isPgsql) {
            DB::statement("select set_config('app.current_org_id', ?, false)", [$organizationId ?? '']);
        }
    }
}
