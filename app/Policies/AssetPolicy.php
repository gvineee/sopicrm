<?php

namespace App\Policies;

use App\Domain\Assets\Models\Asset;
use App\Models\User;

class AssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('assets.assets.view');
    }

    public function view(User $user, Asset $asset): bool
    {
        return $asset->organization_id === $user->organization_id
            && $user->can('assets.assets.view');
    }

    public function create(User $user): bool
    {
        return $user->can('assets.assets.manage');
    }

    public function update(User $user, Asset $asset): bool
    {
        return $asset->organization_id === $user->organization_id
            && $user->can('assets.assets.manage');
    }
}
