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

    // Everything the read-only BioStar client needs. It lives here rather than
    // being read from `env()` at the call site because `config:cache` — which
    // any real deployment runs — makes `env()` return null outside this
    // directory, and a BioStar client that silently loses its base URL in
    // production is exactly the kind of failure that only shows up there.
    'biostar' => [
        'base_url' => env('BIOSTAR_BASE_URL', ''),
        'username' => env('BIOSTAR_USERNAME', ''),
        'password' => env('BIOSTAR_PASSWORD', ''),
        'request_timeout_ms' => (int) env('BIOSTAR_REQUEST_TIMEOUT_MS', 15000),

        // The LAN server presents a certificate signed by a private CA.
        // Pointing at that CA is the correct fix; verification is only relaxed
        // where a deployment has explicitly been configured that way.
        'ca_cert_path' => env('BIOSTAR_CA_CERT_PATH'),
        'verify_tls' => filter_var(env('BIOSTAR_VERIFY_TLS', true), FILTER_VALIDATE_BOOLEAN),

        // BioStar 2 creates user id 1 as its own built-in `Administrator`
        // account when the server is installed. It is an operator login for
        // the access system, not a member of staff, and the first live sync
        // duly filed it as a person awaiting a department. Configurable
        // because an install that has since reused that record for a real
        // person must be able to say so.
        'ignored_user_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('BIOSTAR_IGNORED_USER_IDS', '1')),
        ), fn (string $id) => $id !== '')),
    ],
];
