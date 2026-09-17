<?php

namespace App\Domain\Devices\Actions;

use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceSyncCommand;

/**
 * Drains one device's pending/retry commands, oldest `command_version`
 * first, through App\Domain\Devices\Actions\ProcessDeviceSyncCommandAction.
 * In production this is what a scheduled job/connector poll loop calls per
 * device; in TEST MODE it is also exposed as an explicit "run connector
 * tick" button (App\Http\Controllers\Devices\DeviceSimulatorController) so
 * the sync queue's pending→processing→succeeded/failed/retry/dead-letter
 * lifecycle is directly observable and testable rather than happening
 * invisibly on a timer.
 */
class RunDeviceConnectorTickAction
{
    public function __construct(private readonly ProcessDeviceSyncCommandAction $process) {}

    /**
     * @return array{processed: int, succeeded: int, failed: int, pending: int}
     */
    public function execute(Device $device): array
    {
        $commands = DeviceSyncCommand::query()
            ->where('device_id', $device->id)
            ->whereIn('status', ['pending', 'retry'])
            ->orderBy('command_version')
            ->get();

        $summary = ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'pending' => 0];

        foreach ($commands as $command) {
            $result = $this->process->execute($command);
            $summary['processed']++;

            match ($result->status) {
                'succeeded' => $summary['succeeded']++,
                'failed', 'dead_letter' => $summary['failed']++,
                default => $summary['pending']++,
            };
        }

        return $summary;
    }
}
