<?php

use App\Http\Controllers\Assets\AssetController;
use App\Http\Controllers\Assets\AssetIncidentController;
use App\Http\Controllers\Assets\CustodyTransactionController;
use Illuminate\Support\Facades\Route;

/**
 * Assets module's Inertia routes (docs/architecture.md §3.1), ASSETS-01.
 * Every action is additionally Policy-checked inside the controller —
 * route middleware alone (`auth`) is never the authorization boundary.
 */
Route::middleware(['auth', 'verified'])->prefix('assets')->name('assets.')->group(function (): void {
    Route::get('/', [AssetController::class, 'index'])->name('index');
    Route::get('/create', [AssetController::class, 'create'])->name('create');
    Route::post('/', [AssetController::class, 'store'])->name('store');
    Route::get('/{asset}', [AssetController::class, 'show'])->name('show');

    Route::post('/{asset}/issue', [CustodyTransactionController::class, 'issue'])->name('issue');
    Route::post('/{asset}/transfer', [CustodyTransactionController::class, 'transfer'])->name('transfer');
    Route::post('/{asset}/incidents', [AssetIncidentController::class, 'store'])->name('incidents.store');

    Route::post('/custody/{transaction}/confirm-receipt', [CustodyTransactionController::class, 'confirmReceipt'])->name('custody.confirm-receipt');
    Route::post('/custody/{transaction}/request-return', [CustodyTransactionController::class, 'requestReturn'])->name('custody.request-return');
    Route::post('/custody/{transaction}/return', [CustodyTransactionController::class, 'return'])->name('custody.return');

    Route::post('/incidents/{incident}/decide', [AssetIncidentController::class, 'decide'])->name('incidents.decide');
});
