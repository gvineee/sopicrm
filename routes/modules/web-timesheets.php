<?php

use App\Http\Controllers\Timesheets\AttendanceAdjustmentController;
use App\Http\Controllers\Timesheets\TimesheetController;
use Illuminate\Support\Facades\Route;

/**
 * Timesheets module Inertia routes (docs/architecture.md Module Contribution
 * Convention). REQ-TSH-*.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::prefix('timesheets')->name('timesheets.')->group(function (): void {
        Route::get('/', [TimesheetController::class, 'index'])->name('index');
        Route::post('/generate', [TimesheetController::class, 'generate'])->name('generate');
        Route::get('/{timesheet}', [TimesheetController::class, 'show'])->name('show');
        Route::post('/{timesheet}/submit', [TimesheetController::class, 'submit'])->name('submit');
        Route::post('/{timesheet}/approve', [TimesheetController::class, 'approve'])->name('approve');
        Route::post('/{timesheet}/reject', [TimesheetController::class, 'reject'])->name('reject');
        Route::post('/{timesheet}/lock', [TimesheetController::class, 'lock'])->name('lock');
    });

    Route::prefix('attendance-adjustments')->name('attendance-adjustments.')->group(function (): void {
        Route::get('/', [AttendanceAdjustmentController::class, 'index'])->name('index');
        Route::post('/', [AttendanceAdjustmentController::class, 'store'])->name('store');
        Route::post('/{adjustment}/decide', [AttendanceAdjustmentController::class, 'decide'])->name('decide');
    });
});
