<?php

namespace App\Policies;

use App\Domain\Devices\Models\ExternalIdentifierMapping;
use App\Models\User;

class ExternalIdentifierMappingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('devices.external_mappings.view');
    }

    public function view(User $user, ExternalIdentifierMapping $mapping): bool
    {
        return $mapping->organization_id === $user->organization_id
            && $user->can('devices.external_mappings.view');
    }

    public function manage(User $user, ExternalIdentifierMapping $mapping): bool
    {
        return $mapping->organization_id === $user->organization_id
            && $user->can('devices.external_mappings.manage');
    }
}
