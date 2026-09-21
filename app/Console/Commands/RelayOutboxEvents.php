<?php

namespace App\Console\Commands;

use App\Domain\Shared\Models\OutboxEvent;
use App\Jobs\Shared\ProcessOutboxEventJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * docs/architecture.md §5 "Outbox" / DEC-014: "queue jobs idempotent"; this
 * is the relay half — reads unprocessed outbox rows (across every tenant;
 * it is inherently a cross-tenant system process, unlike almost everything
 * else in this app) and dispatches one idempotent
 * App\Jobs\Shared\ProcessOutboxEventJob per row. Intended to run on a short
 * recurring schedule (registered in routes/console.php by whichever module
 * needs it live — this command itself is Foundation-owned infrastructure,
 * scheduling it is not).
 *
 * QUEUE-01: under a real restricted Postgres role, `withoutTenantScope()`
 * only removes the Eloquent-layer scope — it does nothing about the actual
 * `outbox_events_tenant_isolation` RLS policy, which (with no
 * `app.current_org_id` ever set for this inherently cross-tenant process)
 * would make this query see ZERO rows from every tenant, always, silently.
 * `app.outbox_relay_active` is the narrow, SELECT-only escape hatch added
 * for exactly this (migration
 * `2026_09_21_120000_add_system_relay_read_policy_to_outbox_events_table.php`)
 * — set transaction-scoped (`is_local=true`) so it can never leak into an
 * unrelated later query on a reused connection.
 */
class RelayOutboxEvents extends Command
{
    protected $signature = 'outbox:relay {--limit=200}';

    protected $description = 'Dispatch a queue job for every unprocessed outbox event.';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $events = DB::transaction(function () use ($limit) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("select set_config('app.outbox_relay_active', '1', true)");
            }

            return OutboxEvent::withoutTenantScope()
                ->unprocessed()
                ->limit($limit)
                ->get(['id']);
        });

        foreach ($events as $event) {
            ProcessOutboxEventJob::dispatch($event->id);
        }

        $this->info("Dispatched {$events->count()} outbox event(s).");

        return self::SUCCESS;
    }
}
