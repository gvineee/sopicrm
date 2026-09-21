<?php

use App\Http\Controllers\Devices\CredentialController;
use App\Http\Controllers\Devices\DeviceController;
use App\Http\Controllers\Devices\DeviceSimulatorController;
use App\Http\Controllers\Devices\ExternalIdentifierMappingController;
use Illuminate\Support\Facades\Route;

/**
 * Devices module's human-facing Inertia routes (docs/architecture.md Module
 * Contribution Convention). The machine-facing device-connector API lives in
 * routes/modules/api-devices.php and is owned separately — this file never
 * defines anything under /api and never edits that file.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::get('devices/create', [DeviceController::class, 'create'])->name('devices.create');
    Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');
    Route::get('devices/{device}/edit', [DeviceController::class, 'edit'])->name('devices.edit');
    Route::match(['put', 'patch'], 'devices/{device}', [DeviceController::class, 'update'])->name('devices.update');
    Route::get('devices/{device}', [DeviceController::class, 'show'])->name('devices.show');

    Route::post('devices/{device}/simulator/status', [DeviceSimulatorController::class, 'setStatus'])->name('devices.simulator.status');
    Route::post('devices/{device}/simulator/tick', [DeviceSimulatorController::class, 'tick'])->name('devices.simulator.tick');
    Route::post('devices/{device}/simulator/generate-event', [DeviceSimulatorController::class, 'generateEvent'])->name('devices.simulator.generate-event');

    Route::get('credentials', [CredentialController::class, 'index'])->name('credentials.index');
    Route::post('credentials', [CredentialController::class, 'store'])->name('credentials.store');
    Route::post('credentials/{credential}/reissue', [CredentialController::class, 'reissue'])->name('credentials.reissue');
    Route::post('credential-assignments/{assignment}/revoke', [CredentialController::class, 'revoke'])->name('credentials.revoke');

    Route::get('device-external-mappings', [ExternalIdentifierMappingController::class, 'index'])->name('devices.external-mappings.index');
    Route::post('device-external-mappings/{mapping}/confirm', [ExternalIdentifierMappingController::class, 'confirm'])->name('devices.external-mappings.confirm');
    Route::post('device-external-mappings/{mapping}/ignore', [ExternalIdentifierMappingController::class, 'ignore'])->name('devices.external-mappings.ignore');
});
