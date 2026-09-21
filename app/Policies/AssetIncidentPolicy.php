<?php

namespace App\Policies;

use App\Domain\Assets\Models\AssetIncident;
use App\Models\User;

class AssetIncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('assets.custody.view') || $user->can('assets.incidents.report');
    }

    public function view(User $user, AssetIncident $incident): bool
    {
        return $incident->organization_id === $user->organization_id
            && ($user->can('assets.custody.view') || $incident->reported_by_user_id === $user->id);
    }

    public function report(User $user): bool
    {
        return $user->can('assets.incidents.report');
    }

    public function decide(User $user, AssetIncident $incident): bool
    {
        return $incident->organization_id === $user->organization_id
            && $user->can('assets.incidents.decide');
    }
}
