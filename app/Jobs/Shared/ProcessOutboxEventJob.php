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
 */
class ProcessOutboxEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly string $outboxEventId) {}

    public function handle(): void
    {
        try {
            DB::transaction(function (): void {
                /** @var OutboxEvent|null $event */
                $event = OutboxEvent::withoutTenantScope()
                    ->lockForUpdate()
                    ->find($this->outboxEventId);

                if ($event === null || $event->processed_at !== null) {
                    return; // Already handled — idempotent no-op.
                }

                CurrentOrganization::set($event->organization_id);

                if (DB::connection()->getDriverName() === 'pgsql') {
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
        OutboxEvent::withoutTenantScope()
            ->where('id', $this->outboxEventId)
            ->update([
                'attempts' => DB::raw('attempts + 1'),
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
    }
}
