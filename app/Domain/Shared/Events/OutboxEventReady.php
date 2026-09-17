<?php

namespace App\Domain\Shared\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by App\Jobs\Shared\ProcessOutboxEventJob once an outbox row has been
 * claimed (row-locked, `processed_at` still null) and the tenant context
 * re-established from the row itself. Later modules listen for this with an
 * `$event->eventType` check rather than this module needing to know about
 * every future consumer — keeps the Outbox pattern generic and reusable.
 */
class OutboxEventReady
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $eventType,
        public readonly string $organizationId,
        public readonly string $subjectType,
        public readonly string $subjectId,
        public readonly array $payload,
    ) {}
}
