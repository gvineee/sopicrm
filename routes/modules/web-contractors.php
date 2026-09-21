<?php

use App\Http\Controllers\Contractors\ContractorActController;
use App\Http\Controllers\Contractors\ContractorContractController;
use App\Http\Controllers\Contractors\ContractorController;
use App\Http\Controllers\Contractors\ContractorPaymentController;
use App\Http\Controllers\Contractors\ContractorProjectAssignmentController;
use Illuminate\Support\Facades\Route;

/**
 * Contractors module Inertia routes (docs/architecture.md Module
 * Contribution Convention). A contractor is always scoped to the
 * organization; contracts/assignments/acts/payments all nest under it.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::resource('contractors', ContractorController::class)->except('destroy');

    Route::prefix('contractors/{contractor}/contracts')->name('contractors.contracts.')->group(function (): void {
        Route::post('/', [ContractorContractController::class, 'store'])->name('store');
        Route::get('/{contract}', [ContractorContractController::class, 'show'])->name('show');
        Route::put('/{contract}', [ContractorContractController::class, 'update'])->name('update');
        Route::post('/{contract}/submit-for-approval', [ContractorContractController::class, 'submitForApproval'])->name('submit-for-approval');
        Route::post('/{contract}/approve', [ContractorContractController::class, 'approve'])->name('approve');
        Route::post('/{contract}/reject', [ContractorContractController::class, 'reject'])->name('reject');
        Route::post('/{contract}/close', [ContractorContractController::class, 'close'])->name('close');

        Route::post('/{contract}/payments', [ContractorPaymentController::class, 'store'])->name('payments.store');
    });

    Route::prefix('contractors/{contractor}/project-assignments')->name('contractors.project-assignments.')->group(function (): void {
        Route::post('/{project}', [ContractorProjectAssignmentController::class, 'store'])->name('store');
        Route::delete('/{project}/{assignment}', [ContractorProjectAssignmentController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('contractors/{contractor}/acts')->name('contractors.acts.')->group(function (): void {
        Route::post('/', [ContractorActController::class, 'store'])->name('store');
        Route::post('/{act}/accept', [ContractorActController::class, 'accept'])->name('accept');
        Route::post('/{act}/return', [ContractorActController::class, 'returnAct'])->name('return');
        Route::get('/{act}/attachments/{attachment}', [ContractorActController::class, 'showAttachment'])->name('attachments.show');
    });

    Route::post('contractors/{contractor}/attachments', [ContractorActController::class, 'uploadAttachment'])->name('contractors.attachments.store');
});
