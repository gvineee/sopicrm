<?php

namespace App\Domain\Devices\Actions;

use App\Domain\Devices\Contracts\DeviceAdapterInterface;
use App\Domain\Devices\Models\Device;

/**
 * Updates `last_heartbeat_at` and re-derives `status`. Independent of
 * `sync_status` (spec section 6: online never implies fully synced).
 */
class RecordDeviceHeartbeatAction
{
    public function __construct(private readonly DeviceAdapterInterface $adapter) {}

    public function execute(Device $device): Device
    {
        $status = $this->adapter->pollHeartbeat($device);

        $device->update([
            'last_heartbeat_at' => now(),
            'last_seen_at' => now(),
            'status' => $status,
        ]);

        return $device->refresh();
    }
}
