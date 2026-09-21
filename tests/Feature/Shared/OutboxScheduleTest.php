<?php

use Illuminate\Console\Scheduling\Schedule;

/**
 * QUEUE-01: App\Console\Commands\RelayOutboxEvents existed but nothing ever
 * scheduled it (routes/console.php had no Schedule:: entry at all) —
 * outbox rows would accumulate forever without a human running
 * `outbox:relay` by hand. This proves the schedule registration itself,
 * not just that the command class exists.
 */
test('QUEUE-01: outbox:relay is scheduled every minute without overlapping', function () {
    $schedule = app(Schedule::class);

    $event = collect($schedule->events())->first(fn ($event) => str_contains($event->command ?? '', 'outbox:relay'));

    expect($event)->not->toBeNull('outbox:relay must be registered on the schedule (routes/console.php)')
        ->and($event->getExpression())->toBe('* * * * *');
});
