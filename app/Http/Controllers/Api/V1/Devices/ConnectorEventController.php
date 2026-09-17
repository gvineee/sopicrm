<?php

namespace App\Http\Controllers\Api\V1\Devices;

use App\Domain\Devices\Actions\IngestRawAccessEventAction;
use App\Domain\Devices\Models\Device;
use App\Http\Controllers\Controller;
use App\Http\Requests\Devices\StoreConnectorEventsRequest;
use Illuminate\Http\JsonResponse;

class ConnectorEventController extends Controller
{
    public function store(
        StoreConnectorEventsRequest $request,
        string $deviceId,
        IngestRawAccessEventAction $ingest,
    ): JsonResponse {
        $device = Device::query()->findOrFail($deviceId);
        $events = [];

        foreach ($request->events() as $eventData) {
            $event = $ingest->execute($device, $eventData);
            $events[] = [
                'id' => $event->id,
                'nativeEventId' => $event->native_event_id,
                'streamEpoch' => $event->stream_epoch,
                'matchedCredential' => $event->credential_id !== null,
            ];
        }

        return response()->json(['events' => $events], 202);
    }
}
