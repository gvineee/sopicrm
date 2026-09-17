<?php

namespace App\Console\Commands;

use App\Domain\Shared\Models\OutboxEvent;
use App\Jobs\Shared\ProcessOutboxEventJob;
use Illuminate\Console\Command;

/**
 * docs/architecture.md §5 "Outbox" / DEC-014: "queue jobs idempotent"; this
 * is the relay half — reads unprocessed outbox rows (across every tenant;
 * it is inherently a cross-tenant system process, unlike almost everything
 * else in this app) and dispatches one idempotent
 * App\Jobs\Shared\ProcessOutboxEventJob per row. Intended to run on a short
 * recurring schedule (registered in routes/console.php by whichever module
 * needs it live — this command itself is Foundation-owned infrastructure,
 * scheduling it is not).
 */
class RelayOutboxEvents extends Command
{
    protected $signature = 'outbox:relay {--limit=200}';

    protected $description = 'Dispatch a queue job for every unprocessed outbox event.';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $events = OutboxEvent::withoutTenantScope()
            ->unprocessed()
            ->limit($limit)
            ->get(['id']);

        foreach ($events as $event) {
            ProcessOutboxEventJob::dispatch($event->id);
        }

        $this->info("Dispatched {$events->count()} outbox event(s).");

        return self::SUCCESS;
    }
}
