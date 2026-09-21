<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Database\Seeders\RbacBaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * ADMIN-02: removing access is the sensitive direction (unlike
 * AssignUserRoleAction), so this requires a reason, refuses to let a user
 * remove their own role (self-lockout), and — mirroring
 * RevokePlatformAdminAction's "never remove the last admin" guard — refuses
 * to remove `owner`/`system_admin` from the last user in the organization
 * who holds either, so an organization can never be left with nobody able
 * to reach this same admin screen again.
 */
class RemoveUserRoleAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(User $target, string $role, User $actor, string $reason): User
    {
        if (! in_array($role, RbacBaseSeeder::ROLES, true)) {
            throw new InvalidArgumentException("Unknown role: {$role}");
        }

        if ($target->is($actor)) {
            throw new AuthorizationException('A user may not remove their own role.');
        }

        if (in_array($role, ['owner', 'system_admin'], true) && $this->isLastAdminCapableUser($target)) {
            throw new RuntimeException('Cannot remove this role from the last owner/system_admin user in the organization.');
        }

        $target->removeRole($role);

        $this->auditLogger->log(
            action: 'auth.user_role.removed',
            target: $target,
            after: ['role' => $role],
            reason: $reason,
            actor: $actor,
            organizationId: $target->organization_id,
        );

        return $target->fresh();
    }

    /**
     * `roles()` (spatie's HasRoles trait) already filters by the ambient
     * permissions-team id (the request's current organization, set by
     * App\Http\Middleware\SetCurrentOrganization) — only `organization_id`
     * on `users` itself needs an explicit filter, since App\Models\User
     * deliberately carries no BelongsToOrganization global scope (see that
     * model's own docblock).
     */
    private function isLastAdminCapableUser(User $target): bool
    {
        $adminCapableUserIds = User::query()
            ->where('organization_id', $target->organization_id)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['owner', 'system_admin']))
            ->pluck('id');

        return $adminCapableUserIds->count() <= 1 && $adminCapableUserIds->contains($target->id);
    }
}
