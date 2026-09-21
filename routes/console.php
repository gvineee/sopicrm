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

/**
 * QUEUE-01 (deferred remainder): incremental attendance reconstruction so a
 * raw event doesn't sit unreconstructed forever between manual triggers.
 * Every 5 minutes is a deliberate balance — frequent enough that a
 * clocked-in employee's session reflects reality soon after the event
 * arrives, not so frequent that it re-scans every organization/employee
 * pointlessly when nothing new has happened (the command itself is a
 * no-op per employee unless a genuinely new event exists since their own
 * checkpoint).
 */
Schedule::command('attendance:process-incremental')->everyFiveMinutes()->withoutOverlapping();

/**
 * QUEUE-01 (deferred remainder): proactive device-silence detection —
 * catches a connector that stopped heartbeating entirely, which the
 * reactive device_fault trigger in RecordDeviceHeartbeatAction cannot see
 * by construction (it only runs when a heartbeat DOES arrive).
 */
Schedule::command('devices:health-check')->everyFifteenMinutes()->withoutOverlapping();

/**
 * NOTIFY-01 (deferred remainder): the scheduled half of Telegram reporting
 * — App\Console\Commands\TelegramSendDigest's own docblock has the full
 * design. Once daily, offset from `notifications:notify-due-items`'s own
 * 08:00 slot so the two don't contend for the same minute.
 */
Schedule::command('telegram:send-digest')->dailyAt('08:30')->withoutOverlapping();
