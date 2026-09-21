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
        Route::get('/{timesheet}/pdf', [TimesheetController::class, 'pdf'])->name('pdf');
        Route::post('/{timesheet}/submit', [TimesheetController::class, 'submit'])->name('submit');
        Route::post('/{timesheet}/approve', [TimesheetController::class, 'approve'])->name('approve');
        Route::post('/{timesheet}/reject', [TimesheetController::class, 'reject'])->name('reject');
        Route::post('/{timesheet}/lock', [TimesheetController::class, 'lock'])->name('lock');
        Route::get('/{timesheet}/email/preview', [TimesheetController::class, 'emailPreview'])->name('email.preview');
        Route::post('/{timesheet}/email', [TimesheetController::class, 'emailSend'])->name('email.send');
        Route::post('/{timesheet}/email/{delivery}/retry', [TimesheetController::class, 'emailRetry'])->name('email.retry');
    });

    // TIMESHEET-EMAIL-02: batch send. Not nested under a single
    // {timesheet} — a batch spans an arbitrary selected set.
    Route::prefix('timesheet-email-batches')->name('timesheet-email-batches.')->group(function (): void {
        Route::get('/', [TimesheetController::class, 'emailBatchIndex'])->name('index');
        Route::post('/', [TimesheetController::class, 'emailBatchStore'])->name('store');
        Route::get('/{batch}', [TimesheetController::class, 'emailBatchShow'])->name('show');
        Route::post('/{batch}/cancel', [TimesheetController::class, 'emailBatchCancel'])->name('cancel');
        Route::post('/{batch}/deliveries/{delivery}/retry', [TimesheetController::class, 'emailBatchDeliveryRetry'])->name('deliveries.retry');
    });

    Route::prefix('attendance-adjustments')->name('attendance-adjustments.')->group(function (): void {
        Route::get('/', [AttendanceAdjustmentController::class, 'index'])->name('index');
        Route::post('/', [AttendanceAdjustmentController::class, 'store'])->name('store');
        Route::post('/{adjustment}/decide', [AttendanceAdjustmentController::class, 'decide'])->name('decide');
    });
});
