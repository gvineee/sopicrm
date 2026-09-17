<?php

return [
    'adapter' => env('DEVICES_ADAPTER', 'simulator'),
    'sync_command_max_attempts' => (int) env('DEVICES_SYNC_MAX_ATTEMPTS', 5),
    'connector_replay_window_seconds' => (int) env('DEVICES_CONNECTOR_REPLAY_WINDOW_SECONDS', 300),
    'clock_drift_threshold_seconds' => (int) env('DEVICES_CLOCK_DRIFT_THRESHOLD_SECONDS', 300),
];
