<?php

namespace App\Jobs\Shared;

use App\Domain\Shared\Events\OutboxEventReady;
use App\Domain\Shared\Models\OutboxEvent;
use App\Domain\Shared\Services\CurrentOrganization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * docs/architecture.md §5 "Outbox" / DEC-014: "queue jobs idempotent."
 * Dispatched (at-least-once) by App\Console\Commands\RelayOutboxEvents.
 * Idempotency is enforced with `SELECT ... FOR UPDATE` + a `processed_at`
 * check inside one transaction: if two workers somehow pick up the same
 * event, or the queue redelivers it after a worker crash post-commit, the
 * second run finds `processed_at` already set (or the row locked, then
 * already-processed once the lock is released) and is a safe no-op.
 *
 * Re-derives tenant context from the STORED row (`organization_id`), never
 * from any ambient state — queue workers don't inherit the web request's
 * context (docs/architecture.md §4).
 *
 * QUEUE-01: this job only knows an id when it starts — it cannot know which
 * tenant a row belongs to until it has actually read that row, which under
 * a real restricted Postgres role is a chicken-and-egg problem against the
 * standard `organization_id = current_setting('app.current_org_id')` RLS
 * policy (that policy alone would make the initial lookup see zero rows,
 * always, since no org context exists yet — a silent, permanent no-op, not
 * a retry). Fixed with a two-phase pattern, both phases inside the SAME
 * transaction: (1) briefly set the narrow, SELECT-only
 * `app.outbox_relay_active` escape hatch (migration
 * `2026_09_21_120000_add_system_relay_read_policy_to_outbox_events_table.php`)
 * just long enough to locate this one row by id; (2) the instant its real
 * `organization_id` is known, clear that flag and set `app.current_org_id`
 * to that ONE tenant for everything else in the transaction — the event
 * dispatch and the `processed_at` write both then run under completely
 * normal, single-tenant RLS, never under the cross-tenant escape hatch.
 * `failed()` has the identical bootstrapping problem (it also starts from
 * nothing but an id) and uses the same two-phase pattern.
 */
class ProcessOutboxEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly string $outboxEventId) {}

    public function handle(): void
    {
        try {
            DB::transaction(function (): void {
                $isPgsql = DB::connection()->getDriverName() === 'pgsql';

                if ($isPgsql) {
                    DB::statement("select set_config('app.outbox_relay_active', '1', true)");
                }

                /** @var OutboxEvent|null $event */
                $event = OutboxEvent::withoutTenantScope()
                    ->lockForUpdate()
                    ->find($this->outboxEventId);

                if ($event === null || $event->processed_at !== null) {
                    return; // Already handled — idempotent no-op.
                }

                CurrentOrganization::set($event->organization_id);

                if ($isPgsql) {
                    // Narrow back down to exactly this one tenant — the
                    // cross-tenant escape hatch above is closed again before
                    // anything else in this transaction runs.
                    DB::statement("select set_config('app.outbox_relay_active', '', true)");
                    DB::statement("select set_config('app.current_org_id', ?, true)", [$event->organization_id]);
                }

                OutboxEventReady::dispatch(
                    $event->event_type,
                    $event->organization_id,
                    $event->subject_type,
                    $event->subject_id,
                    $event->payload,
                );

                $event->update(['processed_at' => now()]);
            });
        } finally {
            CurrentOrganization::clear();
        }
    }

    public function failed(Throwable $exception): void
    {
        try {
            DB::transaction(function () use ($exception): void {
                $isPgsql = DB::connection()->getDriverName() === 'pgsql';

                if ($isPgsql) {
                    DB::statement("select set_config('app.outbox_relay_active', '1', true)");
                }

                /** @var OutboxEvent|null $event */
                $event = OutboxEvent::withoutTenantScope()->find($this->outboxEventId);

                if ($event === null) {
                    return;
                }

                if ($isPgsql) {
                    // The system-relay flag is SELECT-only by design (see
                    // the migration) — it does not, and must not, satisfy
                    // an UPDATE's WITH CHECK. This UPDATE below only
                    // succeeds because the session is now scoped to this
                    // event's own real tenant, exactly like handle() above.
                    DB::statement("select set_config('app.outbox_relay_active', '', true)");
                    DB::statement("select set_config('app.current_org_id', ?, true)", [$event->organization_id]);
                }

                CurrentOrganization::set($event->organization_id);

                $event->update([
                    'attempts' => DB::raw('attempts + 1'),
                    'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                ]);
            });
        } finally {
            CurrentOrganization::clear();
        }
    }
}
