<?php

use App\Http\Controllers\Api\V1\Devices\ConnectorCommandController;
use App\Http\Controllers\Api\V1\Devices\ConnectorEventController;
use App\Http\Controllers\Api\V1\Devices\ConnectorHeartbeatController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])
    ->prefix('v1/device-connector/devices/{deviceId}')
    ->name('api.device-connector.')
    ->group(function (): void {
        Route::get('/commands', [ConnectorCommandController::class, 'index'])
            ->middleware('device-connector:device-connector:commands.read')
            ->name('commands.index');

        Route::post('/commands/{commandId}/acknowledge', [ConnectorCommandController::class, 'acknowledge'])
            ->middleware(['device-connector:device-connector:commands.write', 'idempotency'])
            ->name('commands.acknowledge');

        Route::post('/events', [ConnectorEventController::class, 'store'])
            ->middleware(['device-connector:device-connector:events.write', 'idempotency'])
            ->name('events.store');

        Route::post('/heartbeat', [ConnectorHeartbeatController::class, 'store'])
            ->middleware(['device-connector:device-connector:heartbeat.write', 'idempotency'])
            ->name('heartbeat.store');
    });
