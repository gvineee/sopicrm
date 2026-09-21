<?php

namespace App\Policies;

use App\Domain\Devices\Models\Site;
use App\Domain\Devices\Support\CompanyScope;
use App\Models\User;

/**
 * TENANT-01: Sites reuse the Devices module's own permissions (a Site is
 * part of that domain, docs/data-model.md spec section 6) — no new
 * permission pair for this slice.
 */
class SitePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('owner') || $user->can('devices.view');
    }

    public function view(User $user, Site $site): bool
    {
        return $site->organization_id === $user->organization_id
            && ($user->hasRole('owner') || $user->can('devices.view'))
            && CompanyScope::allows($site->company_id, $user);
    }

    public function manage(User $user, Site $site): bool
    {
        return $site->organization_id === $user->organization_id
            && ($user->hasRole('owner') || $user->can('devices.manage'));
    }
}
