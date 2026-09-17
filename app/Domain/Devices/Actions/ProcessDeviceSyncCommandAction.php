<?php

namespace App\Domain\Devices\Actions;

use App\Domain\Devices\Contracts\DeviceAdapterInterface;
use App\Domain\Devices\Models\DeviceSyncCommand;

/**
 * Applies exactly ONE queued command and persists the outcome — the only
 * writer of `device_sync_commands.status`/`acknowledged_at` after
 * enqueueing. Implements the spec section 6 hard rule that a stale in-flight
 * retry must never overwrite a newer command's effect: before calling the
 * adapter, it checks whether a HIGHER-versioned command for the same
 * (device, target entity) has already succeeded, and if so marks this one
 * `failed` (superseded) without ever touching the device.
 */
class ProcessDeviceSyncCommandAction
{
    public function __construct(private readonly DeviceAdapterInterface $adapter) {}

    public function execute(DeviceSyncCommand $command): DeviceSyncCommand
    {
        if (! in_array($command->status, ['pending', 'retry'], true)) {
            return $command;
        }

        $latestSucceededVersion = DeviceSyncCommand::query()
            ->where('device_id', $command->device_id)
            ->where('target_entity_type', $command->target_entity_type)
            ->where('target_entity_id', $command->target_entity_id)
            ->where('status', 'succeeded')
            ->max('command_version');

        if ($latestSucceededVersion !== null && $command->command_version <= $latestSucceededVersion) {
            $command->update([
                'status' => 'failed',
                'last_error' => "superseded: a newer command (v{$latestSucceededVersion}) for this target has already succeeded",
            ]);

            return $command->refresh();
        }

        $command->update(['status' => 'processing']);

        $device = $command->device;
        $result = $this->adapter->applyCommand($device, $command);

        if ($result->applied) {
            $command->update([
                'status' => 'succeeded',
                'acknowledged_at' => now(),
                'attempts' => $command->attempts + 1,
                'last_error' => null,
            ]);
        } elseif ($result->retryable) {
            $attempts = $command->attempts + 1;
            $maxAttempts = (int) config('devices.sync_command_max_attempts');

            $command->update([
                'attempts' => $attempts,
                'status' => $attempts >= $maxAttempts ? 'dead_letter' : 'retry',
                'last_error' => $result->error,
            ]);
        } else {
            $command->update([
                'attempts' => $command->attempts + 1,
                'status' => 'failed',
                'last_error' => $result->error,
            ]);
        }

        $this->refreshDeviceSyncStatus($command);

        return $command->refresh();
    }

    private function refreshDeviceSyncStatus(DeviceSyncCommand $command): void
    {
        $device = $command->device;

        $hasOutstanding = DeviceSyncCommand::query()
            ->where('device_id', $device->id)
            ->whereIn('status', ['pending', 'retry', 'processing'])
            ->exists();

        $hasError = DeviceSyncCommand::query()
            ->where('device_id', $device->id)
            ->where('status', 'dead_letter')
            ->exists();

        $device->update([
            'sync_status' => $hasError ? 'error' : ($hasOutstanding ? 'pending' : 'in_sync'),
        ]);
    }
}
