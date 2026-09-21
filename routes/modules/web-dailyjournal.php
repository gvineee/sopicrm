<?php

use App\Http\Controllers\DailyJournal\DailyReportController;
use Illuminate\Support\Facades\Route;

/**
 * Daily Journal module's Inertia routes (docs/architecture.md §3.1). Nested
 * under a project since a journal entry always belongs to exactly one
 * project (spec section 11 form: "პროექტი/თარიღი ..."). Every action is
 * additionally Policy-checked inside the controller — route middleware
 * alone (`auth`) is never the authorization boundary.
 */
Route::middleware(['auth', 'verified'])
    ->get('/daily-journal', [DailyReportController::class, 'projects'])
    ->name('daily-journal.projects');

Route::middleware(['auth', 'verified'])
    ->prefix('projects/{project}/daily-journal')
    ->name('daily-journal.')
    ->group(function (): void {
        Route::get('/', [DailyReportController::class, 'index'])->name('index');
        Route::get('/create', [DailyReportController::class, 'create'])->name('create');
        Route::post('/', [DailyReportController::class, 'store'])->name('store');
        Route::get('/{report}', [DailyReportController::class, 'show'])->name('show');
        Route::get('/{report}/edit', [DailyReportController::class, 'edit'])->name('edit');
        Route::put('/{report}', [DailyReportController::class, 'update'])->name('update');
        Route::post('/{report}/submit', [DailyReportController::class, 'submit'])->name('submit');
        Route::post('/{report}/accept', [DailyReportController::class, 'accept'])->name('accept');
        Route::post('/{report}/return', [DailyReportController::class, 'return'])->name('return');
        Route::get('/{report}/revisions', [DailyReportController::class, 'revisions'])->name('revisions');
    });
