<?php

namespace App\Domain\Devices\Contracts;

use App\Domain\Devices\DataTransferObjects\DeviceCommandResult;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceSyncCommand;
use Illuminate\Support\Collection;

/**
 * Spec section 6: "მოწყობილობის რეალური ინტეგრაცია გამოყავი adapter-ის
 * უკან" — every place the app needs to talk to a physical access-control
 * device goes through this interface, never a concrete adapter class
 * directly (bound in App\Providers\Devices\DevicesModuleServiceProvider,
 * config-selected via `config('devices.adapter')`). Two implementations
 * exist side by side (docs/architecture.md §6):
 *   - App\Domain\Devices\Adapters\SimulatorDeviceAdapter — always labeled
 *     "სატესტო რეჟიმი" / "TEST MODE" wherever its data is shown.
 *   - App\Domain\Devices\Adapters\SupremaGSdkAdapter — a real-adapter stub
 *     that documents the G-SDK Device Gateway integration plan and refuses
 *     to claim real-hardware validation it hasn't undergone.
 *
 * Nothing in App\Domain\Devices\Actions may branch on which concrete
 * adapter is bound — the moment a caller needs to know "is this real,"
 * that's exactly what `isSimulator()` is for, so the "always visibly
 * labeled TEST MODE" rule can be enforced generically instead of per call
 * site.
 */
interface DeviceAdapterInterface
{
    /**
     * Human-readable label for wherever this adapter's data is displayed —
     * the simulator's label always renders literally as Georgian "სატესტო
     * რეჟიმი" (TEST MODE) per spec section 6's explicit UI requirement.
     */
    public function label(): string;

    public function isSimulator(): bool;

    /**
     * Reads the device's live capability snapshot (max users/cards,
     * supported card formats, ...). Spec section 6 hard rule: "მოწყობილობის
     * მეხსიერების ზუსტი ლიმიტი არ გამოიგონო — წაიკითხე capability" — a
     * real adapter implementation must call the actual G-SDK Device API,
     * never hardcode a number from a datasheet.
     *
     * @return array<string, mixed>
     */
    public function readCapabilities(Device $device): array;

    /**
     * Applies exactly one queued command to the device (add/update user,
     * revoke credential, sync access group/schedule). The caller
     * (App\Domain\Devices\Actions\ProcessDeviceSyncCommandAction) is solely
     * responsible for persisting the resulting DeviceSyncCommand status —
     * this method is a pure "talk to the device" operation with no
     * persistence side effects of its own.
     */
    public function applyCommand(Device $device, DeviceSyncCommand $command): DeviceCommandResult;

    /**
     * Cheap reachability/status probe, independent of the sync command
     * queue — spec section 6: "Online არ ნიშნავს, რომ ყველა ბარათი
     * სინქრონიზებულია," i.e. this must never be inferred FROM sync_status.
     *
     * @return 'online'|'offline'|'degraded'|'unknown'
     */
    public function pollHeartbeat(Device $device): string;

    /**
     * Pulls raw access-log events the device has recorded since the given
     * durable checkpoint (App\Domain\Devices\Models\DeviceCheckpoint),
     * used both for normal polling and for outage-recovery catch-up.
     * Overlap with already-ingested events is expected and safe — the
     * caller (App\Domain\Devices\Actions\IngestRawAccessEventAction) dedups
     * on (device_id, native_event_id, stream_epoch).
     *
     * @return Collection<int, array<string, mixed>> raw event payloads, each
     *                                               shaped like App\Domain\Devices\Actions\IngestRawAccessEventAction's
     *                                               `$eventData` parameter
     */
    public function pullEvents(Device $device, int $sinceStreamEpoch, int $sinceNativeEventId, int $limit = 100): Collection;
}
