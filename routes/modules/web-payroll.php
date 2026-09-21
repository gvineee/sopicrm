<?php

use App\Http\Controllers\Payroll\AdvanceController;
use App\Http\Controllers\Payroll\DailyPayPolicyController;
use App\Http\Controllers\Payroll\PaymentController;
use App\Http\Controllers\Payroll\PayPeriodController;
use App\Http\Controllers\Payroll\PayRunController;
use Illuminate\Support\Facades\Route;

/**
 * Payroll module Inertia routes (docs/architecture.md Module Contribution
 * Convention). spec section 8.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::prefix('payroll/pay-periods')->name('payroll.pay-periods.')->group(function (): void {
        Route::get('/', [PayPeriodController::class, 'index'])->name('index');
        Route::post('/', [PayPeriodController::class, 'store'])->name('store');
        Route::post('/{payPeriod}/close', [PayPeriodController::class, 'close'])->name('close');
    });

    Route::prefix('payroll/pay-runs')->name('payroll.pay-runs.')->group(function (): void {
        Route::get('/', [PayRunController::class, 'index'])->name('index');
        Route::post('/', [PayRunController::class, 'store'])->name('store');
        Route::get('/{payRun}', [PayRunController::class, 'show'])->name('show');
        Route::post('/{payRun}/calculate', [PayRunController::class, 'calculate'])->name('calculate');
        Route::post('/{payRun}/review', [PayRunController::class, 'review'])->name('review');
        Route::post('/{payRun}/approve', [PayRunController::class, 'approve'])->name('approve');
        Route::post('/{payRun}/lock', [PayRunController::class, 'lock'])->name('lock');
    });

    Route::prefix('payroll/advances')->name('payroll.advances.')->group(function (): void {
        Route::get('/', [AdvanceController::class, 'index'])->name('index');
        Route::post('/', [AdvanceController::class, 'store'])->name('store');
    });

    Route::post('payroll/payments', [PaymentController::class, 'store'])->name('payroll.payments.store');

    Route::prefix('payroll/daily-pay-policy')->name('payroll.daily-pay-policy.')->group(function (): void {
        Route::get('/', [DailyPayPolicyController::class, 'edit'])->name('edit');
        Route::put('/', [DailyPayPolicyController::class, 'update'])->name('update');
    });
});
