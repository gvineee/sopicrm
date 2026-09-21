<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * QUEUE-01: App\Console\Commands\RelayOutboxEvents existed but was never
 * actually scheduled anywhere — outbox rows would accumulate forever
 * without a human remembering to run `outbox:relay` by hand.
 * `withoutOverlapping()` matters specifically because
 * App\Jobs\Shared\ProcessOutboxEventJob's idempotency guard (row lock +
 * `processed_at` check) only protects a single event row from being
 * double-processed — it does nothing to stop two overlapping relay runs
 * from both dispatching a job for the SAME still-unprocessed row before
 * either job has run, which would just mean two queued jobs racing for the
 * same lock rather than a correctness bug, but is still wasted work worth
 * avoiding outright.
 */
Schedule::command('outbox:relay')->everyMinute()->withoutOverlapping();

/**
 * NOTIFY-01: the scheduled half of the `overdue`/`tool_return_due`
 * notification types — see App\Console\Commands\NotifyDueItems's own
 * docblock. Once daily is deliberate: both checks are per-calendar-day
 * deduplicated already, so running more often would do nothing but repeat
 * the same "nothing new today" scan.
 */
Schedule::command('notifications:notify-due-items')->dailyAt('08:00')->withoutOverlapping();
