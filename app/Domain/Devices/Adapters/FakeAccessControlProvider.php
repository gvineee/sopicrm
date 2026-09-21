<?php

namespace App\Domain\Devices\Adapters;

use App\Domain\Devices\Contracts\AccessControlProviderInterface;
use App\Domain\Devices\DataTransferObjects\DeviceCommandResult;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceSyncCommand;
use Illuminate\Support\Collection;

/** Deterministic provider for tests and local development; never hardware. */
final class FakeAccessControlProvider implements AccessControlProviderInterface
{
    private SimulatorDeviceAdapter $simulator;

    public function __construct()
    {
        $this->simulator = new SimulatorDeviceAdapter;
    }

    public function label(): string
    {
        return 'Fake Access Control Provider (TEST MODE)';
    }

    public function isSimulator(): bool
    {
        return true;
    }

    public function readCapabilities(Device $device): array
    {
        return $this->simulator->readCapabilities($device);
    }

    public function applyCommand(Device $device, DeviceSyncCommand $command): DeviceCommandResult
    {
        return $this->simulator->applyCommand($device, $command);
    }

    public function pollHeartbeat(Device $device): string
    {
        return $this->simulator->pollHeartbeat($device);
    }

    public function pullEvents(Device $device, int $sinceStreamEpoch, int $sinceNativeEventId, int $limit = 100): Collection
    {
        return $this->simulator->pullEvents($device, $sinceStreamEpoch, $sinceNativeEventId, $limit);
    }
}
