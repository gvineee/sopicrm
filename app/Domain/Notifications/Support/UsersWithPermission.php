<?php

namespace App\Domain\Notifications\Support;

use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;

/**
 * NOTIFY-01: resolves every active user in an organization who CURRENTLY
 * holds a given permission — for a broadcast-style trigger (device fault,
 * timesheet exception) with no single natural "owner" recipient. Checks
 * `$user->can()` per user rather than a role-only join, because ADMIN-02
 * added per-user grant/deny overrides that a role-only query would miss
 * entirely (a user granted this permission directly, with no role that
 * grants it, must still be found; a user with a role that would grant it
 * but an explicit deny-override must NOT be found).
 */
class UsersWithPermission
{
    /**
     * @return Collection<int, User>
     */
    public static function inOrganization(string $organizationId, string $permission): Collection
    {
        $previousTeamId = app(PermissionRegistrar::class)->getPermissionsTeamId();
        $previousOrganizationId = CurrentOrganization::id();

        try {
            CurrentOrganization::set($organizationId);
            app(PermissionRegistrar::class)->setPermissionsTeamId($organizationId);

            return User::query()
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->get()
                ->filter(fn (User $user): bool => $user->can($permission))
                ->values();
        } finally {
            CurrentOrganization::set($previousOrganizationId);
            app(PermissionRegistrar::class)->setPermissionsTeamId($previousTeamId);
        }
    }
}
