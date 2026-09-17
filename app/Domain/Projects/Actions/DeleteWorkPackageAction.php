<?php

namespace App\Domain\Projects\Actions;

use App\Domain\Projects\Models\WorkPackage;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Refuses to delete a work package that already has tasks attached
 * (`tasks.work_package_id`) — the Tasks module owns that table, but this
 * module still must not silently orphan/renumber another module's rows.
 */
class DeleteWorkPackageAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(WorkPackage $workPackage, User $actor): void
    {
        DB::transaction(function () use ($workPackage, $actor): void {
            $hasTasks = DB::table('tasks')
                ->where('work_package_id', $workPackage->id)
                ->exists();

            if ($hasTasks) {
                throw ValidationException::withMessages([
                    'work_package' => ['ამ სამუშაო პაკეტს ჯერ კიდევ აქვს დაკავშირებული დავალებები.'],
                ]);
            }

            $before = $workPackage->only(['project_id', 'project_location_id', 'name', 'description']);
            $workPackage->delete();

            $this->auditLogger->log(
                action: 'projects.wbs.work_package_deleted',
                target: $workPackage,
                before: $before,
                actor: $actor,
            );
        });
    }
}
