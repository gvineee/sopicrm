<?php

return [
    'adapter' => env('DEVICES_ADAPTER', 'simulator'),
    'sync_command_max_attempts' => (int) env('DEVICES_SYNC_MAX_ATTEMPTS', 5),
    'connector_replay_window_seconds' => (int) env('DEVICES_CONNECTOR_REPLAY_WINDOW_SECONDS', 300),
    'clock_drift_threshold_seconds' => (int) env('DEVICES_CLOCK_DRIFT_THRESHOLD_SECONDS', 300),
    // Used by App\Domain\Devices\Services\DeviceStatusResolver to turn
    // heartbeat age into online/degraded/offline. These were previously
    // undefined (the resolver's config() calls silently fell back to 0),
    // which made every device with any measurable heartbeat age show
    // "offline" regardless of how recently it actually checked in.
    // Defaults assume the device-connector's own default 5s poll interval
    // (services/device-connector README): a handful of missed ticks is
    // "degraded", a couple of minutes of silence is "offline".
    'heartbeat_degraded_after_seconds' => (int) env('DEVICES_HEARTBEAT_DEGRADED_AFTER_SECONDS', 60),
    'heartbeat_offline_after_seconds' => (int) env('DEVICES_HEARTBEAT_OFFLINE_AFTER_SECONDS', 180),

    // BIO-01: first integration stage is read-only by design — BioStar owns
    // devices/cards/access, the CRM only copies user metadata and events.
    // Defaults to false (disabled) regardless of environment; must be
    // explicitly opted into once a write integration (card enrollment,
    // access-group/schedule sync) has been pilot-confirmed against real
    // hardware, not just implemented. The same env var name gates the
    // adapter-side check in services/device-connector/src/adapters/
    // suprema-device-gateway.js — both boundaries must agree.
    'biostar_write_dispatch_enabled' => filter_var(env('BIOSTAR_WRITE_DISPATCH_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
];
