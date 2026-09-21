<?php

use App\Http\Controllers\Contractors\TaskContractorAssignmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Projects\ProjectDocumentController;
use App\Http\Controllers\Projects\ProjectLocationController;
use App\Http\Controllers\Projects\ProjectMembershipController;
use App\Http\Controllers\Tasks\TaskController;
use Illuminate\Support\Facades\Route;

/**
 * Projects + Tasks module Inertia routes (docs/architecture.md Module
 * Contribution Convention). Tasks are always nested under a project — spec
 * section 10: a task always belongs to exactly one project.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('tasks-calendar', [DashboardController::class, 'calendar'])->name('tasks.calendar');

    Route::resource('projects', ProjectController::class);
    Route::post('projects/{project}/status', [ProjectController::class, 'changeStatus'])->name('projects.status');

    Route::post('projects/{project}/members', [ProjectMembershipController::class, 'store'])->name('projects.members.store');
    Route::delete('projects/{project}/members/{membership}', [ProjectMembershipController::class, 'destroy'])->name('projects.members.destroy');

    Route::post('projects/{project}/locations', [ProjectLocationController::class, 'store'])->name('projects.locations.store');
    Route::put('projects/{project}/locations/{location}', [ProjectLocationController::class, 'update'])->name('projects.locations.update');
    Route::delete('projects/{project}/locations/{location}', [ProjectLocationController::class, 'destroy'])->name('projects.locations.destroy');

    Route::post('projects/{project}/documents', [ProjectDocumentController::class, 'store'])->name('projects.documents.store');
    Route::get('projects/{project}/documents/{document}', [ProjectDocumentController::class, 'download'])->name('projects.documents.download');
    Route::delete('projects/{project}/documents/{document}', [ProjectDocumentController::class, 'destroy'])->name('projects.documents.destroy');

    Route::prefix('projects/{project}/tasks')->name('projects.tasks.')->group(function (): void {
        Route::get('/', [TaskController::class, 'index'])->name('index');
        Route::get('/create', [TaskController::class, 'create'])->name('create');
        Route::post('/', [TaskController::class, 'store'])->name('store');
        Route::get('/{task}', [TaskController::class, 'show'])->name('show');
        Route::get('/{task}/edit', [TaskController::class, 'edit'])->name('edit');
        Route::put('/{task}', [TaskController::class, 'update'])->name('update');

        Route::post('/{task}/assign', [TaskController::class, 'assign'])->name('assign');
        Route::post('/{task}/start', [TaskController::class, 'start'])->name('start');
        Route::post('/{task}/block', [TaskController::class, 'block'])->name('block');
        Route::post('/{task}/unblock', [TaskController::class, 'unblock'])->name('unblock');
        Route::post('/{task}/submit', [TaskController::class, 'submit'])->name('submit');
        Route::post('/{task}/submissions/{submission}/accept', [TaskController::class, 'acceptSubmission'])->name('submissions.accept');
        Route::post('/{task}/submissions/{submission}/return', [TaskController::class, 'returnSubmission'])->name('submissions.return');
        Route::post('/{task}/cancel', [TaskController::class, 'cancel'])->name('cancel');
        Route::post('/{task}/reopen', [TaskController::class, 'reopen'])->name('reopen');
        Route::post('/{task}/dependencies', [TaskController::class, 'addDependency'])->name('dependencies.store');
        Route::post('/{task}/attachments', [TaskController::class, 'uploadAttachment'])->name('attachments.store');
        Route::get('/{task}/attachments/{attachment}', [TaskController::class, 'showAttachment'])->name('attachments.show');
        Route::post('/{task}/comments', [TaskController::class, 'storeComment'])->name('comments.store');
        Route::patch('/{task}/checklist-items/{checklistItem}', [TaskController::class, 'toggleChecklistItem'])->name('checklist-items.update');

        Route::post('/{task}/contractor-assignments', [TaskContractorAssignmentController::class, 'store'])->name('contractor-assignments.store');
        Route::delete('/{task}/contractor-assignments/{assignee}', [TaskContractorAssignmentController::class, 'destroy'])->name('contractor-assignments.destroy');
    });
});
