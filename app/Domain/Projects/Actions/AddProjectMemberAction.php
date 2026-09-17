<?php

namespace App\Domain\Projects\Actions;

use App\Domain\Auth\Models\ProjectMembership;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds (or reactivates) a project membership. Spec section 3: project
 * membership + role jointly decide access — this is the write side of that
 * table, kept idempotent (re-adding an already-active member is a no-op
 * validation error, not a duplicate row) since `project_memberships` has a
 * partial-unique index on `(organization_id, user_id, project_id) WHERE
 * removed_at IS NULL`.
 */
class AddProjectMemberAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(Project $project, string $userId, ?string $roleContext, User $actor): ProjectMembership
    {
        return DB::transaction(function () use ($project, $userId, $roleContext, $actor): ProjectMembership {
            $existing = $project->memberships()
                ->where('user_id', $userId)
                ->whereNull('removed_at')
                ->first();

            if ($existing !== null) {
                throw ValidationException::withMessages([
                    'user_id' => ['ეს მომხმარებელი უკვე არის პროექტის წევრი.'],
                ]);
            }

            $membership = ProjectMembership::create([
                'project_id' => $project->id,
                'user_id' => $userId,
                'role_context' => $roleContext ?: 'member',
                'added_by_user_id' => $actor->id,
            ]);

            $this->auditLogger->log(
                action: 'projects.membership.added',
                target: $membership,
                after: $membership->only(['project_id', 'user_id', 'role_context']),
                actor: $actor,
            );

            return $membership;
        });
    }
}
