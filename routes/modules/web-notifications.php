<?php

use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\TelegramLinkController;
use Illuminate\Support\Facades\Route;

/**
 * NOTIFY-01. JSON endpoints (not Inertia::render) — the notification
 * bell/global search are polled/queried from the shared layout on every
 * page, not a full page navigation. Every action derives its subject from
 * $request->user() only (see each controller's own docblock) — no
 * route-parameter-identified "another user's" resource exists here.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('search', [GlobalSearchController::class, 'index'])->name('search.index');

    Route::get('settings/notifications', [NotificationController::class, 'preferencesPage'])->name('settings.notifications.edit');

    Route::prefix('notifications')->name('notifications.')->group(function (): void {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::post('/{notification}/read', [NotificationController::class, 'markRead'])->name('read');
        Route::post('/read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
        Route::get('/preferences', [NotificationController::class, 'preferences'])->name('preferences.show');
        Route::put('/preferences', [NotificationController::class, 'updatePreferences'])->name('preferences.update');

        Route::prefix('telegram')->name('telegram.')->group(function (): void {
            Route::get('/', [TelegramLinkController::class, 'show'])->name('show');
            Route::post('/link', [TelegramLinkController::class, 'link'])->name('link');
            Route::post('/link/complete-demo', [TelegramLinkController::class, 'completeDemo'])->name('complete-demo');
            Route::post('/reports', [TelegramLinkController::class, 'sendReport'])->name('reports.send');
            Route::post('/deliveries/{delivery}/retry', [TelegramLinkController::class, 'retryDelivery'])->name('deliveries.retry');
        });
    });
});
