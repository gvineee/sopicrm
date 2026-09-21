<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;

/**
 * ADMIN-02: removes a previously direct-granted permission
 * (GrantUserPermissionOverrideAction). This only ever returns the user to
 * their role-derived baseline — it can never reduce access below what their
 * role(s) already provide — so, unlike RemoveUserRoleAction/
 * DenyUserPermissionAction, no reason or self-action guard is required.
 */
class RevokeUserPermissionOverrideAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(User $target, string $permissionName, User $actor): User
    {
        $target->revokePermissionTo($permissionName);

        $this->auditLogger->log(
            action: 'auth.user_permission_override.revoked',
            target: $target,
            after: ['permission' => $permissionName],
            reason: null,
            actor: $actor,
            organizationId: $target->organization_id,
        );

        return $target->fresh();
    }
}
