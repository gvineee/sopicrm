<?php

namespace App\Domain\Devices\Services;

use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceSyncCommand;

/**
 * Derives sync status only from the latest desired command for each target.
 * Historical failures must not keep a device in error after a newer command
 * succeeds, and an online heartbeat never implies synchronized state.
 */
final class DeviceSyncStatusResolver
{
    /** @return 'in_sync'|'pending'|'error' */
    public function statusFor(Device $device): string
    {
        $latestByTarget = DeviceSyncCommand::query()
            ->where('device_id', $device->id)
            ->orderByDesc('command_version')
            ->orderByDesc('created_at')
            ->get()
            ->unique(fn (DeviceSyncCommand $command): string => implode(':', [
                $command->target_entity_type ?? 'none',
                $command->target_entity_id ?? 'none',
            ]));

        if ($latestByTarget->contains(fn (DeviceSyncCommand $command): bool => in_array($command->status, ['failed', 'dead_letter'], true))) {
            return 'error';
        }

        if ($latestByTarget->contains(fn (DeviceSyncCommand $command): bool => in_array($command->status, ['pending', 'processing', 'retry'], true))) {
            return 'pending';
        }

        return 'in_sync';
    }

    public function refresh(Device $device): Device
    {
        $device->update(['sync_status' => $this->statusFor($device)]);

        return $device->refresh();
    }
}
