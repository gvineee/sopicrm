<?php

namespace App\Domain\Projects\Actions;

use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\WorkPackage;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateWorkPackageAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(Project $project, string $name, ?string $description, ?string $projectLocationId, User $actor): WorkPackage
    {
        return DB::transaction(function () use ($project, $name, $description, $projectLocationId, $actor): WorkPackage {
            if ($projectLocationId !== null && ! $project->locations()->whereKey($projectLocationId)->exists()) {
                throw ValidationException::withMessages([
                    'project_location_id' => ['ლოკაცია არ მიეკუთვნება ამ პროექტს.'],
                ]);
            }

            $workPackage = WorkPackage::create([
                'project_id' => $project->id,
                'project_location_id' => $projectLocationId,
                'name' => $name,
                'description' => $description,
            ]);

            $this->auditLogger->log(
                action: 'projects.wbs.work_package_created',
                target: $workPackage,
                after: $workPackage->only(['project_id', 'project_location_id', 'name', 'description']),
                actor: $actor,
            );

            return $workPackage;
        });
    }
}
