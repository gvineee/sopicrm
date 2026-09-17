<?php

namespace App\Domain\Projects\Actions;

use App\Domain\Projects\Models\ProjectLocation;
use App\Domain\Projects\Models\WorkPackage;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateWorkPackageAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(WorkPackage $workPackage, string $name, ?string $description, ?string $projectLocationId, User $actor): WorkPackage
    {
        return DB::transaction(function () use ($workPackage, $name, $description, $projectLocationId, $actor): WorkPackage {
            if ($projectLocationId !== null) {
                $belongs = ProjectLocation::where('project_id', $workPackage->project_id)
                    ->whereKey($projectLocationId)
                    ->exists();

                if (! $belongs) {
                    throw ValidationException::withMessages([
                        'project_location_id' => ['ლოკაცია არ მიეკუთვნება ამ პროექტს.'],
                    ]);
                }
            }

            $before = $workPackage->only(['project_location_id', 'name', 'description']);

            $workPackage->update([
                'project_location_id' => $projectLocationId,
                'name' => $name,
                'description' => $description,
            ]);

            $this->auditLogger->log(
                action: 'projects.wbs.work_package_updated',
                target: $workPackage,
                before: $before,
                after: $workPackage->only(['project_location_id', 'name', 'description']),
                actor: $actor,
            );

            return $workPackage;
        });
    }
}
