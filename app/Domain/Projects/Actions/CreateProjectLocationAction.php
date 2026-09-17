<?php

namespace App\Domain\Projects\Actions;

use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectLocation;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates one optional WBS node (spec section 10: "დონეები optional" —
 * project -> corpus/zone -> floor -> space, arbitrary depth, every level
 * skippable). A cycle cannot occur on create (the parent, if any, already
 * exists and the new node has no children yet) — see
 * UpdateProjectLocationAction for the re-parenting case that can.
 */
class CreateProjectLocationAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(Project $project, string $levelType, string $name, ?string $parentLocationId, User $actor): ProjectLocation
    {
        return DB::transaction(function () use ($project, $levelType, $name, $parentLocationId, $actor): ProjectLocation {
            if ($parentLocationId !== null) {
                $parentBelongsToProject = $project->locations()->whereKey($parentLocationId)->exists();
                if (! $parentBelongsToProject) {
                    throw ValidationException::withMessages([
                        'parent_location_id' => ['მშობელი ლოკაცია არ მიეკუთვნება ამ პროექტს.'],
                    ]);
                }
            }

            $location = ProjectLocation::create([
                'project_id' => $project->id,
                'parent_location_id' => $parentLocationId,
                'level_type' => $levelType,
                'name' => $name,
            ]);

            $this->auditLogger->log(
                action: 'projects.wbs.location_created',
                target: $location,
                after: $location->only(['project_id', 'parent_location_id', 'level_type', 'name']),
                actor: $actor,
            );

            return $location;
        });
    }
}
