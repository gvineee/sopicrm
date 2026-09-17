<?php

namespace App\Http\Controllers\Api\V1\Devices;

use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceCapability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Devices\StoreConnectorHeartbeatRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ConnectorHeartbeatController extends Controller
{
    public function store(StoreConnectorHeartbeatRequest $request, string $deviceId): JsonResponse
    {
        $device = Device::query()->findOrFail($deviceId);

        DB::transaction(function () use ($request, $device): void {
            $device->update([
                'status' => $request->validated('status'),
                'connector_version' => $request->validated('connector_version'),
                'firmware_version' => $request->validated('firmware_version'),
                'last_heartbeat_at' => now(),
            ]);

            /** @var array<string, mixed> $capabilities */
            $capabilities = $request->validated('capabilities', []);

            foreach ($capabilities as $key => $value) {
                DeviceCapability::query()->updateOrCreate(
                    ['device_id' => $device->id, 'capability_key' => $key],
                    ['capability_value' => is_array($value) ? $value : ['value' => $value], 'read_at' => now()],
                );
            }
        });

        return response()->json([
            'deviceId' => $device->id,
            'status' => $device->refresh()->status,
            'syncStatus' => $device->sync_status,
        ]);
    }
}
