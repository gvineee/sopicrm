<?php

use App\Http\Controllers\Api\V1\Tasks\OfflineSyncController;
use Illuminate\Support\Facades\Route;

/**
 * Tasks module's /api/v1 routes (docs/architecture.md §3.1) — the mobile
 * offline-queue-facing transport (spec section 18), mirroring
 * api-dailyjournal.php's exact split: Inertia for the normal desktop/mobile
 * web pages, /api/v1 for what resources/js/lib/offlineQueue.ts replays
 * (PWA-01). Both endpoints are submission-mutating POSTs, so both require
 * `idempotency` per spec section 20.
 */
Route::middleware(['auth:sanctum', 'idempotency'])
    ->prefix('v1/projects/{project}/tasks/{task}')
    ->name('api.tasks.')
    // TM-07: same scoped binding as the Inertia routes — a queued offline
    // item replayed against another project's task id 404s at the binding.
    ->scopeBindings()
    ->group(function (): void {
        Route::post('/offline-attachments', [OfflineSyncController::class, 'storeAttachment'])->name('offline-attachments.store');
        Route::post('/offline-submissions', [OfflineSyncController::class, 'storeSubmission'])->name('offline-submissions.store');
    });
