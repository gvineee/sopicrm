<?php

namespace App\Domain\Projects\Actions;

use App\Domain\Projects\Models\ProjectLocation;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a WBS location. Refuses when it still has child locations or
 * work packages attached — the DB FK would otherwise silently null out the
 * children's `parent_location_id`/`project_location_id` (both declared
 * `nullOnDelete()`), quietly detaching part of the tree instead of the user
 * making that choice explicitly. Reassign or delete the children first.
 */
class DeleteProjectLocationAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(ProjectLocation $location, User $actor): void
    {
        DB::transaction(function () use ($location, $actor): void {
            if ($location->children()->exists()) {
                throw ValidationException::withMessages([
                    'location' => ['ჯერ წაშალეთ ან გადაანაცვლეთ ამ ლოკაციის შვილობილი ლოკაციები.'],
                ]);
            }

            if ($location->workPackages()->exists()) {
                throw ValidationException::withMessages([
                    'location' => ['ამ ლოკაციას ჯერ კიდევ აქვს დაკავშირებული სამუშაო პაკეტები.'],
                ]);
            }

            $before = $location->only(['project_id', 'parent_location_id', 'level_type', 'name']);
            $location->delete();

            $this->auditLogger->log(
                action: 'projects.wbs.location_deleted',
                target: $location,
                before: $before,
                actor: $actor,
            );
        });
    }
}
