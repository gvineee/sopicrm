<?php

use App\Http\Controllers\Attendance\AttendanceAnomalyController;
use App\Http\Controllers\Attendance\AttendanceSessionController;
use App\Http\Controllers\Attendance\ShiftAssignmentController;
use App\Http\Controllers\Attendance\ShiftTemplateController;
use Illuminate\Support\Facades\Route;

/**
 * Attendance module Inertia routes (docs/architecture.md Module Contribution
 * Convention). REQ-ATT-01/02/03..09.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::prefix('attendance/shift-templates')->name('attendance.shift-templates.')->group(function (): void {
        Route::get('/', [ShiftTemplateController::class, 'index'])->name('index');
        Route::get('/create', [ShiftTemplateController::class, 'create'])->name('create');
        Route::post('/', [ShiftTemplateController::class, 'store'])->name('store');
        Route::get('/{shiftTemplate}/edit', [ShiftTemplateController::class, 'edit'])->name('edit');
        Route::put('/{shiftTemplate}', [ShiftTemplateController::class, 'update'])->name('update');
        Route::delete('/{shiftTemplate}', [ShiftTemplateController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('attendance/shift-assignments')->name('attendance.shift-assignments.')->group(function (): void {
        Route::get('/', [ShiftAssignmentController::class, 'index'])->name('index');
        Route::post('/', [ShiftAssignmentController::class, 'store'])->name('store');
        Route::post('/{shiftAssignment}/end', [ShiftAssignmentController::class, 'end'])->name('end');
    });

    Route::prefix('attendance/sessions')->name('attendance.sessions.')->group(function (): void {
        Route::get('/', [AttendanceSessionController::class, 'index'])->name('index');
        Route::post('/reconstruct', [AttendanceSessionController::class, 'reconstruct'])->name('reconstruct');
        Route::get('/{session}', [AttendanceSessionController::class, 'show'])->name('show');
        Route::post('/{session}/attribute-project', [AttendanceSessionController::class, 'attributeProject'])->name('attribute-project');
    });

    Route::prefix('attendance/anomalies')->name('attendance.anomalies.')->group(function (): void {
        Route::get('/', [AttendanceAnomalyController::class, 'index'])->name('index');
        Route::post('/{anomaly}/resolve', [AttendanceAnomalyController::class, 'resolve'])->name('resolve');
    });
});
