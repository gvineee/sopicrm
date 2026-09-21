<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Models\UserPermissionDenial;
use App\Domain\Auth\Support\PermissionDenialCache;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;

/**
 * ADMIN-02: removes a deny-override (DenyUserPermissionAction), restoring
 * whatever the target's role(s)/grant-overrides would otherwise provide.
 * This only ever increases access back to baseline, never below it, so no
 * self-action guard is needed here (unlike creating the denial).
 */
class RemoveUserPermissionDenialAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(UserPermissionDenial $denial, User $actor): void
    {
        // App\Models\User carries no BelongsToOrganization global scope
        // (see that model's own docblock), so a plain relation load is
        // sufficient here — nothing to bypass.
        $target = $denial->user;
        $permissionName = $denial->permission_name;

        $denial->delete();

        PermissionDenialCache::forget($target->organization_id, $target->id);

        $this->auditLogger->log(
            action: 'auth.user_permission_denial.removed',
            target: $target,
            after: ['permission' => $permissionName],
            reason: null,
            actor: $actor,
            organizationId: $target->organization_id,
        );
    }
}
