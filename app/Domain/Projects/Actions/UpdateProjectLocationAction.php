<?php

namespace App\Domain\Projects\Actions;

use App\Domain\Projects\Exceptions\ProjectLocationCycleException;
use App\Domain\Projects\Models\ProjectLocation;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateProjectLocationAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(ProjectLocation $location, string $levelType, string $name, ?string $parentLocationId, User $actor): ProjectLocation
    {
        return DB::transaction(function () use ($location, $levelType, $name, $parentLocationId, $actor): ProjectLocation {
            if ($parentLocationId !== null) {
                if ($parentLocationId === $location->id) {
                    throw new ProjectLocationCycleException;
                }

                $parent = ProjectLocation::where('project_id', $location->project_id)->find($parentLocationId);
                if ($parent === null) {
                    throw ValidationException::withMessages([
                        'parent_location_id' => ['მშობელი ლოკაცია არ მიეკუთვნება ამ პროექტს.'],
                    ]);
                }

                $this->assertNotDescendant($location, $parent);
            }

            $before = $location->only(['parent_location_id', 'level_type', 'name']);

            $location->update([
                'parent_location_id' => $parentLocationId,
                'level_type' => $levelType,
                'name' => $name,
            ]);

            $this->auditLogger->log(
                action: 'projects.wbs.location_updated',
                target: $location,
                before: $before,
                after: $location->only(['parent_location_id', 'level_type', 'name']),
                actor: $actor,
            );

            return $location;
        });
    }

    /**
     * Walks the candidate new parent's own ancestor chain — if the location
     * being moved appears anywhere in it, the move would create a cycle.
     */
    private function assertNotDescendant(ProjectLocation $location, ProjectLocation $candidateParent): void
    {
        $current = $candidateParent;
        $guard = 0;

        while ($current !== null && $guard < 1000) {
            if ($current->id === $location->id) {
                throw new ProjectLocationCycleException;
            }

            $current = $current->parent_location_id !== null
                ? ProjectLocation::find($current->parent_location_id)
                : null;

            $guard++;
        }
    }
}
