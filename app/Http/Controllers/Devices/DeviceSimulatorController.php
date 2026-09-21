<?php

namespace App\Http\Controllers\Devices;

use App\Domain\Devices\Actions\IngestRawAccessEventAction;
use App\Domain\Devices\Actions\RunDeviceConnectorTickAction;
use App\Domain\Devices\Adapters\SimulatorDeviceAdapter;
use App\Domain\Devices\Models\Device;
use App\Http\Controllers\Controller;
use App\Http\Requests\Devices\GenerateSimulatorEventRequest;
use App\Http\Requests\Devices\SetDeviceSimulatorStatusRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

/**
 * spec section 6: "Simulator-ის ეკრანს მუდმივად ეწეროს "სატესტო რეჟიმი"."
 * Every action here refuses outright unless the bound adapter is the
 * simulator (config('devices.adapter') === 'simulator') — these controls
 * must never exist as a live path against real hardware. Devices WEB UI
 * ownership only; see App\Http\Controllers\Devices\DeviceController's
 * header for the exact boundary with the parallel backend/API work.
 */
class DeviceSimulatorController extends Controller
{
    use AuthorizesRequests;

    public function setStatus(SetDeviceSimulatorStatusRequest $request, Device $device): RedirectResponse
    {
        $this->authorize('operateSimulator', $device);
        $this->ensureSimulatorMode();

        $device->update(['status' => $request->validated('status')]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'სატესტო რეჟიმი: მოწყობილობის სტატუსი განახლდა.',
        ]);
    }

    public function tick(Device $device, RunDeviceConnectorTickAction $action): RedirectResponse
    {
        $this->authorize('operateSimulator', $device);
        $this->ensureSimulatorMode();

        $summary = $action->execute($device);

        return back()->with('toast', [
            'type' => 'success',
            'message' => "სატესტო რეჟიმი: დამუშავდა {$summary['processed']}, წარმატებული {$summary['succeeded']}, ჩავარდნილი {$summary['failed']}, მოლოდინში {$summary['pending']}.",
        ]);
    }

    public function generateEvent(
        GenerateSimulatorEventRequest $request,
        Device $device,
        SimulatorDeviceAdapter $adapter,
        IngestRawAccessEventAction $ingest,
    ): RedirectResponse {
        $this->authorize('operateSimulator', $device);
        $this->ensureSimulatorMode();

        $payload = $adapter->generateEventPayload(
            device: $device,
            nativeEventId: (int) $request->validated('native_event_id'),
            streamEpoch: (int) $request->validated('stream_epoch'),
            cardHex: $request->validated('card_hex') ?: null,
            cardType: $request->validated('card_type') ?: null,
            direction: (string) $request->validated('direction'),
            eventCode: $request->validated('event_code') ?: 'access_granted',
        );

        $ingest->execute($device, $payload);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'სატესტო რეჟიმი: ტესტური მოვლენა დაფიქსირდა.',
        ]);
    }

    private function ensureSimulatorMode(): void
    {
        abort_unless(
            config('devices.adapter') === 'simulator',
            403,
            'სიმულატორის კონტროლები ხელმისაწვდომია მხოლოდ სატესტო რეჟიმში.',
        );
    }
}
