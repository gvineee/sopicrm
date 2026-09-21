<?php

namespace App\Policies;

use App\Domain\Assets\Models\Maintenance;
use App\Models\User;

/**
 * REQ-AST-07 remainder: maintenance scheduling reuses `assets.assets.manage`
 * (the same permission that gates registering/updating an Asset itself) —
 * scheduling service for an asset is an asset-management action, not a
 * separate authority, so no new permission was added.
 */
class MaintenancePolicy
{
    public function create(User $user): bool
    {
        return $user->can('assets.assets.manage');
    }

    public function update(User $user, Maintenance $maintenance): bool
    {
        return $maintenance->organization_id === $user->organization_id
            && $user->can('assets.assets.manage');
    }
}
