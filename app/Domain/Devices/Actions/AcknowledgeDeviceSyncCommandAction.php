<?php

namespace App\Domain\Devices\Actions;

use App\Domain\Devices\Models\DeviceSyncCommand;
use App\Domain\Devices\Services\DeviceSyncStatusResolver;

class AcknowledgeDeviceSyncCommandAction
{
    public function __construct(private readonly DeviceSyncStatusResolver $syncStatusResolver) {}

    public function execute(DeviceSyncCommand $command, string $result, ?string $error = null): DeviceSyncCommand
    {
        if (in_array($command->status, ['succeeded', 'failed', 'dead_letter'], true)) {
            return $command;
        }

        $newerSucceededVersion = DeviceSyncCommand::query()
            ->where('device_id', $command->device_id)
            ->where('target_entity_type', $command->target_entity_type)
            ->where('target_entity_id', $command->target_entity_id)
            ->where('command_version', '>', $command->command_version)
            ->where('status', 'succeeded')
            ->max('command_version');

        if ($newerSucceededVersion !== null) {
            $command->update([
                'status' => 'failed',
                'last_error' => "superseded: command v{$newerSucceededVersion} already succeeded",
            ]);
            $this->syncStatusResolver->refresh($command->device);

            return $command->refresh();
        }

        $attempts = $command->attempts + 1;
        $status = match ($result) {
            'succeeded' => 'succeeded',
            'retry' => $attempts >= (int) config('devices.sync_command_max_attempts', 5) ? 'dead_letter' : 'retry',
            default => 'failed',
        };

        $command->update([
            'status' => $status,
            'attempts' => $attempts,
            'last_error' => $status === 'succeeded' ? null : $error,
            'acknowledged_at' => $status === 'succeeded' ? now() : null,
        ]);

        if ($status === 'succeeded') {
            $command->device()->update(['last_sync_at' => now()]);
        }

        $this->syncStatusResolver->refresh($command->device);

        return $command->refresh();
    }
}
