<?php

use App\Http\Controllers\Api\V1\DailyJournal\DailyReportController;
use Illuminate\Support\Facades\Route;

/**
 * Daily Journal module's /api/v1 routes (docs/architecture.md §3.1) — the
 * mobile/offline-queue-facing transport for this module (spec section 18:
 * "Inertia ჩვეულებრივი გვერდებისთვის; /api/v1 ... მობილური offline queue-ის
 * ... საჭიროებისთვის"). `store`/`submit` are submission-mutating POSTs, so
 * they require the `idempotency` middleware per spec section 20 ("ფულის,
 * მარაგისა და submission POST ოპერაციებზე Idempotency-Key").
 */
Route::middleware(['auth:sanctum'])
    ->prefix('v1/projects/{project}/daily-journal')
    ->name('api.daily-journal.')
    ->group(function (): void {
        Route::get('/', [DailyReportController::class, 'index'])->name('index');
        Route::get('/{report}', [DailyReportController::class, 'show'])->name('show');

        Route::middleware(['idempotency'])->group(function (): void {
            Route::post('/', [DailyReportController::class, 'store'])->name('store');
            Route::post('/{report}/submit', [DailyReportController::class, 'submit'])->name('submit');
        });

        Route::put('/{report}', [DailyReportController::class, 'update'])->name('update');
        Route::post('/{report}/accept', [DailyReportController::class, 'accept'])->name('accept');
        Route::post('/{report}/return', [DailyReportController::class, 'return'])->name('return');
    });
