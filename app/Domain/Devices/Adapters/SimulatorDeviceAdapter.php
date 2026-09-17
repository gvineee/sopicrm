<?php

namespace App\Domain\Devices\Adapters;

use App\Domain\Devices\Contracts\DeviceAdapterInterface;
use App\Domain\Devices\DataTransferObjects\DeviceCommandResult;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceSyncCommand;
use Illuminate\Support\Collection;

/**
 * Spec section 6: "შექმენი simulator და ცალკე რეალური adapter. Simulator-ის
 * ეკრანს მუდმივად ეწეროს „სატესტო რეჟიმი"." This adapter is entirely
 * in-process (docs/architecture.md §6 permits either "in-process or a tiny
 * local fake within services/device-connector" — in-process was chosen so
 * the whole ingest/sync/reconcile pipeline is exercisable and Pest-testable
 * without a second running process; see docs/decisions.md).
 *
 * It never talks to real hardware and never claims to — `label()` always
 * renders literally, and every screen showing simulator-sourced data must
 * surface that label per the hard constraint against claiming real-hardware
 * validation from simulator-only runs.
 *
 * Device reachability is driven directly by `devices.status`, which the
 * Devices module's simulator control endpoints
 * (App\Http\Controllers\Devices\DeviceSimulatorController) let an operator
 * flip between online/offline/degraded for a given device — there is no
 * hidden/ambient heartbeat loop pretending to be a real network.
 */
final class SimulatorDeviceAdapter implements DeviceAdapterInterface
{
    public function label(): string
    {
        return 'სატესტო რეჟიმი (Simulator)';
    }

    public function isSimulator(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function readCapabilities(Device $device): array
    {
        return [
            'max_users' => 50_000,
            'max_cards' => 50_000,
            'max_event_log' => 200_000,
            'supports_mifare' => true,
            'supports_em' => true,
            'simulated' => true,
        ];
    }

    public function applyCommand(Device $device, DeviceSyncCommand $command): DeviceCommandResult
    {
        // Spec section 6 hard rule: an offline device cannot acknowledge
        // anything right now — this is the normal, expected shape for
        // "device offline," not an adapter error.
        if ($device->status === 'offline') {
            return DeviceCommandResult::deviceUnreachable(
                'სიმულატორი: მოწყობილობა ამჟამად offline-ია (ტესტური მდგომარეობა) — ბრძანება ველოდება მოწყობილობის დაბრუნებას.'
            );
        }

        if ($device->status === 'degraded') {
            // A degraded link is modeled as "usually gets through, but not
            // guaranteed" — deterministic on the command's own version
            // parity so tests can exercise both branches reliably instead
            // of depending on real randomness.
            if ($command->command_version % 3 === 0) {
                return DeviceCommandResult::deviceUnreachable(
                    'სიმულატორი: მოწყობილობასთან კავშირი არასტაბილურია (degraded) — ბრძანება ხელახლა განიმეორდება.'
                );
            }
        }

        return DeviceCommandResult::success([
            'simulated' => true,
            'command_type' => $command->command_type,
            'applied_at' => now()->toIso8601String(),
        ]);
    }

    public function pollHeartbeat(Device $device): string
    {
        // The simulator never silently overrides an operator's explicit
        // TEST MODE status toggle with a fabricated "real" reading.
        return $device->status;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function pullEvents(Device $device, int $sinceStreamEpoch, int $sinceNativeEventId, int $limit = 100): Collection
    {
        // The simulator only ever produces events through the explicit
        // "generate test event" control (generateEventPayload() below,
        // wired to App\Http\Controllers\Devices\DeviceSimulatorController) —
        // never ambiently — so a poll for new events always returns empty.
        return collect();
    }

    /**
     * Builds one plausible raw event payload for the "TEST MODE" UI's
     * "generate event" control — shaped exactly like what a real connector
     * would hand App\Domain\Devices\Actions\IngestRawAccessEventAction.
     *
     * @return array<string, mixed>
     */
    public function generateEventPayload(
        Device $device,
        int $nativeEventId,
        int $streamEpoch,
        ?string $cardHex,
        ?string $cardType,
        string $direction,
        string $eventCode = 'access_granted',
    ): array {
        return [
            'native_event_id' => $nativeEventId,
            'stream_epoch' => $streamEpoch,
            'raw_device_time' => now()->toIso8601String(),
            'event_code' => $eventCode,
            'event_subcode' => null,
            'direction' => $direction,
            'card_type' => $cardType,
            'card_hex' => $cardHex,
            'payload' => ['source' => 'simulator', 'generated_at' => now()->toIso8601String()],
        ];
    }
}
