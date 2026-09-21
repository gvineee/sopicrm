<?php

use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserAccessController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');

    Route::get('users', [UserAccessController::class, 'index'])->name('users.index');
    Route::get('users/{user}', [UserAccessController::class, 'show'])->name('users.show');
    Route::post('users/{user}/roles', [UserAccessController::class, 'assignRole'])->name('users.roles.assign');
    Route::delete('users/{user}/roles', [UserAccessController::class, 'removeRole'])->name('users.roles.remove');
    Route::post('users/{user}/overrides/grant', [UserAccessController::class, 'grantOverride'])->name('users.overrides.grant');
    Route::post('users/{user}/overrides/revoke', [UserAccessController::class, 'revokeOverride'])->name('users.overrides.revoke');
    Route::post('users/{user}/denials', [UserAccessController::class, 'denyPermission'])->name('users.denials.store');
    Route::delete('users/{user}/denials/{denial}', [UserAccessController::class, 'removeDenial'])->name('users.denials.remove');
});
