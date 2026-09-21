<?php

namespace App\Policies;

use App\Models\User;

/**
 * ADMIN-02: authorization for the user/role/permission-override admin
 * screens (App\Http\Controllers\Admin\{RoleController,UserAccessController}).
 * Every ability here also requires same-organization membership between the
 * actor and the target, mirroring App\Policies\AuditEventPolicy's
 * `organization_id` check — a permission alone is never sufficient for a
 * cross-tenant target, matching this codebase's other tenant-scoped
 * policies.
 */
class UserAccessPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('auth.users.view');
    }

    public function view(User $user, User $target): bool
    {
        return $target->organization_id === $user->organization_id
            && $user->can('auth.users.view');
    }

    public function assignRole(User $user, User $target): bool
    {
        return $target->organization_id === $user->organization_id
            && $user->can('auth.roles.assign');
    }

    public function removeRole(User $user, User $target): bool
    {
        return $target->organization_id === $user->organization_id
            && $user->can('auth.roles.assign');
    }

    public function manageOverride(User $user, User $target): bool
    {
        return $target->organization_id === $user->organization_id
            && $user->can('auth.permissions.override');
    }
}
