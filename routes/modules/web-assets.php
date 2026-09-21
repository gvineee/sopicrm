<?php

use App\Http\Controllers\Assets\AssetController;
use App\Http\Controllers\Assets\AssetIncidentController;
use App\Http\Controllers\Assets\CustodyTransactionController;
use App\Http\Controllers\Assets\MaintenanceController;
use App\Http\Controllers\Assets\StocktakeController;
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
    // Registered before '/{asset}' — two path segments here never collide
    // with that single-segment route, but keeping the specific route first
    // documents the intent explicitly.
    Route::get('/qr/{qrToken}', [AssetController::class, 'scanQr'])->name('scan-qr');
    Route::get('/{asset}', [AssetController::class, 'show'])->name('show');

    Route::post('/{asset}/issue', [CustodyTransactionController::class, 'issue'])->name('issue');
    Route::post('/{asset}/transfer', [CustodyTransactionController::class, 'transfer'])->name('transfer');
    Route::post('/{asset}/incidents', [AssetIncidentController::class, 'store'])->name('incidents.store');
    Route::post('/{asset}/maintenance', [MaintenanceController::class, 'store'])->name('maintenance.store');

    Route::post('/maintenance/{maintenance}/complete', [MaintenanceController::class, 'complete'])->name('maintenance.complete');

    Route::post('/custody/{transaction}/confirm-receipt', [CustodyTransactionController::class, 'confirmReceipt'])->name('custody.confirm-receipt');
    Route::post('/custody/{transaction}/request-return', [CustodyTransactionController::class, 'requestReturn'])->name('custody.request-return');
    Route::post('/custody/{transaction}/return', [CustodyTransactionController::class, 'return'])->name('custody.return');

    Route::post('/incidents/{incident}/decide', [AssetIncidentController::class, 'decide'])->name('incidents.decide');

    Route::get('/stocktakes', [StocktakeController::class, 'index'])->name('stocktakes.index');
    Route::post('/stocktakes', [StocktakeController::class, 'store'])->name('stocktakes.store');
    Route::get('/stocktakes/{stocktake}', [StocktakeController::class, 'show'])->name('stocktakes.show');
    Route::post('/stocktakes/{stocktake}/lines/{line}/scan', [StocktakeController::class, 'scan'])->name('stocktakes.scan');
    Route::post('/stocktakes/{stocktake}/lines/{line}/approve-variance', [StocktakeController::class, 'approveVariance'])->name('stocktakes.approve-variance');
    Route::post('/stocktakes/{stocktake}/complete', [StocktakeController::class, 'complete'])->name('stocktakes.complete');
});
