<?php

namespace App\Policies;

use App\Domain\Devices\Models\Device;
use App\Models\User;

class DevicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('devices.view');
    }

    public function view(User $user, Device $device): bool
    {
        return $device->organization_id === $user->organization_id
            && $user->can('devices.view');
    }

    public function create(User $user): bool
    {
        return $user->can('devices.manage');
    }

    public function update(User $user, Device $device): bool
    {
        return $device->organization_id === $user->organization_id
            && $user->can('devices.manage');
    }

    public function operateSimulator(User $user, Device $device): bool
    {
        return $device->organization_id === $user->organization_id
            && $user->can('devices.simulator.manage');
    }
}
