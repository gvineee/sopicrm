<?php

namespace App\Domain\Devices\Actions;

use App\Domain\Devices\Contracts\DeviceAdapterInterface;
use App\Domain\Devices\Models\Device;
use App\Domain\Notifications\Support\NotificationCreator;
use App\Domain\Notifications\Support\NotificationType;
use App\Domain\Notifications\Support\UsersWithPermission;

/**
 * Updates `last_heartbeat_at` and re-derives `status`. Independent of
 * `sync_status` (spec section 6: online never implies fully synced).
 */
class RecordDeviceHeartbeatAction
{
    public function __construct(private readonly DeviceAdapterInterface $adapter) {}

    public function execute(Device $device): Device
    {
        $previousStatus = $device->status;
        $status = $this->adapter->pollHeartbeat($device);

        $device->update([
            'last_heartbeat_at' => now(),
            'last_seen_at' => now(),
            'status' => $status,
        ]);

        // NOTIFY-01: a fresh transition INTO a faulty state, not every
        // heartbeat while it stays faulty — a device stuck offline for a
        // day must not spam a notification on every poll interval.
        if (in_array($status, ['offline', 'degraded'], true) && ! in_array($previousStatus, ['offline', 'degraded'], true)) {
            $this->notifyDeviceFault($device, $status);
        }

        return $device->refresh();
    }

    private function notifyDeviceFault(Device $device, string $status): void
    {
        foreach (UsersWithPermission::inOrganization($device->organization_id, 'devices.view') as $recipient) {
            NotificationCreator::create(
                recipient: $recipient,
                type: NotificationType::DEVICE_FAULT,
                title: 'მოწყობილობის ხარვეზი',
                message: "მოწყობილობა \"{$device->name}\" გახდა {$status}",
                dedupKey: "device_fault:{$device->id}:{$device->last_heartbeat_at?->timestamp}",
                deepLink: "/devices/{$device->id}",
            );
        }
    }
}
