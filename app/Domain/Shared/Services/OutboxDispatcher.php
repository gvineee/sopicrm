<?php

namespace App\Domain\Shared\Services;

use App\Domain\Shared\Models\OutboxEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * docs/architecture.md §5 "Outbox" / DEC-014: the only supported way to
 * record an outbox event. `record()` does NOT open its own transaction —
 * callers use it INSIDE the same DB transaction as the business write it
 * describes (e.g. `DB::transaction(function () { ...business write...;
 * $dispatcher->record(...); })`), which is the entire point: if the
 * transaction rolls back, the event row never exists either.
 *
 * App\Console\Commands\RelayOutboxEvents is the relay that turns
 * unprocessed rows into queued jobs, at-least-once, after commit.
 */
class OutboxDispatcher
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $eventType, Model $subject, array $payload, ?\DateTimeInterface $availableAt = null): OutboxEvent
    {
        return OutboxEvent::create([
            'organization_id' => CurrentOrganization::requireId(),
            'event_type' => $eventType,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'payload' => $payload,
            'available_at' => $availableAt ?? now(),
            'attempts' => 0,
        ]);
    }
}
