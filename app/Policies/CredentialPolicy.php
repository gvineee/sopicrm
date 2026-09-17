<?php

namespace App\Policies;

use App\Domain\Devices\Models\Credential;
use App\Models\User;

class CredentialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('devices.credentials.view');
    }

    public function view(User $user, Credential $credential): bool
    {
        return $credential->organization_id === $user->organization_id
            && $user->can('devices.credentials.view');
    }

    public function manage(User $user): bool
    {
        return $user->can('devices.credentials.manage');
    }
}
