<?php

use App\Http\Controllers\Platform\OrganizationController;
use Illuminate\Support\Facades\Route;

// Platform administration — above any single tenant. Every route is gated on
// `manage-organizations` (users.is_platform_admin only); the controller
// re-checks it, and the nav entry's visibility is a convenience on top.
Route::middleware(['auth', 'verified', 'can:manage-organizations'])
    ->prefix('platform')
    ->name('platform.')
    ->group(function (): void {
        Route::get('organizations', [OrganizationController::class, 'index'])->name('organizations.index');
        Route::get('organizations/create', [OrganizationController::class, 'create'])->name('organizations.create');
        Route::post('organizations', [OrganizationController::class, 'store'])->name('organizations.store');
        Route::get('organizations/{organization}/edit', [OrganizationController::class, 'edit'])->name('organizations.edit');
        Route::match(['put', 'patch'], 'organizations/{organization}', [OrganizationController::class, 'update'])->name('organizations.update');
        Route::delete('organizations/{organization}', [OrganizationController::class, 'destroy'])->name('organizations.destroy');
    });
