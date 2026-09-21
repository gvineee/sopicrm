<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Models\UserPermissionDenial;
use App\Domain\Auth\Support\PermissionDenialCache;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;

/**
 * ADMIN-02 "deny override": explicitly withholds a permission from one user
 * regardless of what their role(s) would otherwise grant — see
 * database/migrations/2026_09_21_130000_create_user_permission_denials_table.php's
 * docblock for the full precedence this participates in. Idempotent
 * (`firstOrCreate`) so repeating the same deny is not an error. Refuses a
 * user denying themselves (self-lockout) — mirrors
 * RemoveUserRoleAction's identical guard.
 */
class DenyUserPermissionAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(User $target, string $permissionName, User $actor, string $reason): UserPermissionDenial
    {
        if (! Permission::query()->where('name', $permissionName)->exists()) {
            throw new InvalidArgumentException("Unknown permission: {$permissionName}");
        }

        if ($target->is($actor)) {
            throw new AuthorizationException('A user may not deny their own permission.');
        }

        $denial = UserPermissionDenial::query()->firstOrCreate(
            [
                'organization_id' => $target->organization_id,
                'user_id' => $target->id,
                'permission_name' => $permissionName,
            ],
            [
                'reason' => $reason,
                'created_by_user_id' => $actor->id,
                'created_at' => now(),
            ],
        );

        PermissionDenialCache::forget($target->organization_id, $target->id);

        $this->auditLogger->log(
            action: 'auth.user_permission.denied',
            target: $target,
            after: ['permission' => $permissionName],
            reason: $reason,
            actor: $actor,
            organizationId: $target->organization_id,
        );

        return $denial;
    }
}
