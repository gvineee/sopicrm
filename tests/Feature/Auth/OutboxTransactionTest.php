<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Shared\Events\OutboxEventReady;
use App\Domain\Shared\Models\OutboxEvent;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Shared\Services\OutboxDispatcher;
use App\Jobs\Shared\ProcessOutboxEventJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * docs/architecture.md §5 "Outbox" / DEC-014: the event row must be written
 * in the SAME transaction as the business change (rolls back together), and
 * the consumer job must be idempotent under at-least-once delivery.
 */
beforeEach(function () {
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
});

test('a rolled back transaction leaves no outbox row behind', function () {
    $user = User::factory()->create(['organization_id' => $this->organization->id, 'current_organization_id' => $this->organization->id]);

    try {
        DB::transaction(function () use ($user) {
            app(OutboxDispatcher::class)->record('user.touched', $user, ['note' => 'should not survive']);

            throw new RuntimeException('simulated failure after recording the outbox event');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(OutboxEvent::withoutTenantScope()->count())->toBe(0);
});

test('a committed transaction persists both the business write and the outbox row together', function () {
    $user = User::factory()->create(['organization_id' => $this->organization->id, 'current_organization_id' => $this->organization->id]);

    DB::transaction(function () use ($user) {
        $user->forceFill(['name' => 'Updated Name'])->save();
        app(OutboxDispatcher::class)->record('user.touched', $user, ['note' => 'survives']);
    });

    expect($user->fresh()->name)->toBe('Updated Name')
        ->and(OutboxEvent::withoutTenantScope()->where('event_type', 'user.touched')->count())->toBe(1);
});

test('processing the same outbox event twice is a safe no-op the second time', function () {
    Event::fake([OutboxEventReady::class]);

    $user = User::factory()->create(['organization_id' => $this->organization->id, 'current_organization_id' => $this->organization->id]);
    $event = app(OutboxDispatcher::class)->record('user.touched', $user, ['note' => 'once']);

    (new ProcessOutboxEventJob($event->id))->handle();
    (new ProcessOutboxEventJob($event->id))->handle();

    Event::assertDispatchedTimes(OutboxEventReady::class, 1);
    expect(OutboxEvent::withoutTenantScope()->find($event->id)->processed_at)->not->toBeNull();
});

test('the relay command dispatches one job per unprocessed event and skips processed ones', function () {
    Queue::fake();

    $user = User::factory()->create(['organization_id' => $this->organization->id, 'current_organization_id' => $this->organization->id]);
    $pending = app(OutboxDispatcher::class)->record('user.touched', $user, []);
    $alreadyProcessed = app(OutboxDispatcher::class)->record('user.touched', $user, []);
    $alreadyProcessed->update(['processed_at' => now()]);

    $this->artisan('outbox:relay')->assertSuccessful();

    Queue::assertPushed(ProcessOutboxEventJob::class, 1);
    Queue::assertPushed(function (ProcessOutboxEventJob $job) use ($pending) {
        return $job->outboxEventId === $pending->id;
    });
});
