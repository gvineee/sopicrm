<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;

/**
 * ADMIN-02 "grant override": a permission the target's role(s) do not
 * already provide, added directly to them via spatie's own native
 * `givePermissionTo()` — no new table needed for this half, spatie's
 * team-scoped `model_has_permissions` already is exactly this mechanism.
 * Precedence: App\Providers\AppServiceProvider::boot()'s deny-override
 * check still wins over this if both exist for the same permission.
 */
class GrantUserPermissionOverrideAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(User $target, string $permissionName, User $actor): User
    {
        if (! Permission::query()->where('name', $permissionName)->exists()) {
            throw new InvalidArgumentException("Unknown permission: {$permissionName}");
        }

        $target->givePermissionTo($permissionName);

        $this->auditLogger->log(
            action: 'auth.user_permission_override.granted',
            target: $target,
            after: ['permission' => $permissionName],
            reason: null,
            actor: $actor,
            organizationId: $target->organization_id,
        );

        return $target->fresh();
    }
}
