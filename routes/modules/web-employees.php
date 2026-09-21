<?php

use App\Http\Controllers\Employees\EmployeeController;
use App\Http\Controllers\Employees\EmployeeDocumentController;
use App\Http\Controllers\Employees\EmployeeInviteController;
use App\Http\Controllers\Employees\EmployeePhotoController;
use App\Http\Controllers\Employees\EmployeeProjectAssignmentController;
use App\Http\Controllers\Employees\EmploymentController;
use App\Http\Controllers\Employees\InviteAcceptController;
use App\Http\Controllers\Employees\PositionController;
use App\Http\Controllers\Employees\RateHistoryController;
use App\Http\Controllers\Employees\TeamController;
use App\Http\Controllers\Employees\TeamMembershipController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->prefix('employee-invites')->name('employees.invite.')->group(function (): void {
    Route::get('/{token}', [InviteAcceptController::class, 'show'])->name('show');
    Route::post('/{token}', [InviteAcceptController::class, 'store'])->name('accept');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::resource('employees', EmployeeController::class)->except('destroy');

    Route::post('employees/{employee}/rates', [RateHistoryController::class, 'store'])->name('employees.rates.store');
    Route::post('employees/{employee}/invites', [EmployeeInviteController::class, 'store'])->name('employees.invites.store');
    Route::delete('employee-invites/{invite}', [EmployeeInviteController::class, 'destroy'])->name('employees.invites.destroy');
    Route::post('employees/{employee}/termination', [EmploymentController::class, 'store'])->name('employees.termination.store');
    Route::post('employees/{employee}/team-membership', [TeamMembershipController::class, 'store'])->name('employees.team-membership.store');
    Route::post('employees/{employee}/project-assignments', [EmployeeProjectAssignmentController::class, 'store'])->name('employees.project-assignments.store');
    Route::post('employees/{employee}/documents', [EmployeeDocumentController::class, 'store'])->name('employees.documents.store');
    Route::get('employees/{employee}/documents/{attachment}', [EmployeeDocumentController::class, 'download'])->name('employees.documents.download');
    Route::post('employees/{employee}/photo', [EmployeePhotoController::class, 'store'])->name('employees.photo.store');
    Route::get('employees/{employee}/photo', [EmployeePhotoController::class, 'show'])->name('employees.photo.show');

    Route::get('teams', [TeamController::class, 'index'])->name('teams.index');
    Route::post('teams', [TeamController::class, 'store'])->name('teams.store');
    Route::patch('teams/{team}', [TeamController::class, 'update'])->name('teams.update');

    Route::get('positions', [PositionController::class, 'index'])->name('positions.index');
    Route::post('positions', [PositionController::class, 'store'])->name('positions.store');
    Route::patch('positions/{position}', [PositionController::class, 'update'])->name('positions.update');
});
