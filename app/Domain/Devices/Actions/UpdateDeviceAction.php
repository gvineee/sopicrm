<?php

namespace App\Domain\Devices\Actions;

use App\Domain\Devices\Models\Device;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;

class UpdateDeviceAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Device $device, array $data, ?User $actor = null): Device
    {
        $data['timezone'] = $data['timezone'] ?: $data['device_timezone'];
        $data['device_timezone'] = $data['timezone'];
        $before = $device->only(array_keys($data));
        $device->update($data);

        $this->audit->log(
            action: 'devices.device.updated',
            target: $device,
            before: $before,
            after: $device->only(array_keys($data)),
            actor: $actor,
        );

        return $device->refresh();
    }
}
