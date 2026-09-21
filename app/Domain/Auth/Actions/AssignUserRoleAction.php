<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Database\Seeders\RbacBaseSeeder;
use InvalidArgumentException;

/**
 * ADMIN-02: the only supported way to add a role to a user's own current
 * organization (spatie's `assignRole()` is team-scoped by the
 * already-established `PermissionRegistrar::setPermissionsTeamId()` —
 * App\Http\Middleware\SetCurrentOrganization — so no organization_id is
 * passed explicitly here). Granting access is the routine case, so unlike
 * RemoveUserRoleAction this does not require a reason.
 */
class AssignUserRoleAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(User $target, string $role, User $actor): User
    {
        if (! in_array($role, RbacBaseSeeder::ROLES, true)) {
            throw new InvalidArgumentException("Unknown role: {$role}");
        }

        $target->assignRole($role);

        $this->auditLogger->log(
            action: 'auth.user_role.assigned',
            target: $target,
            after: ['role' => $role],
            reason: null,
            actor: $actor,
            organizationId: $target->organization_id,
        );

        return $target->fresh();
    }
}
