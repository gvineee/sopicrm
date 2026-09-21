<?php

namespace App\Http\Controllers\Api\V1\Devices;

use App\Domain\Devices\Actions\AcknowledgeDeviceSyncCommandAction;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceCheckpoint;
use App\Domain\Devices\Models\DeviceSyncCommand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Devices\AcknowledgeConnectorCommandRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConnectorCommandController extends Controller
{
    /**
     * BIO-01: this is the server-side write-dispatch boundary — the
     * connector can only ever act on a command it was handed here, so
     * withholding real-BioStar write commands at this endpoint (rather than
     * only in the adapter) makes read-only mode enforceable even by a
     * connector build that doesn't itself check the flag. Every command type
     * in `device_sync_commands.command_type` is a hardware WRITE (there is
     * no read-type command in this queue) — see the DB enum in
     * database/migrations/2026_09_16_090140_create_devices_domain_tables.php.
     * Withheld commands stay `pending`/`retry` untouched: history and
     * status are preserved and visible (Devices\DeviceController::show()),
     * they are simply never handed to a real adapter to execute.
     */
    public function index(Request $request, string $deviceId): JsonResponse
    {
        $device = Device::query()->findOrFail($deviceId);
        $limit = max(1, min((int) $request->query('limit', 100), 500));

        $writeDispatchBlocked = config('devices.adapter') === 'suprema'
            && ! config('devices.biostar_write_dispatch_enabled');

        $commands = $writeDispatchBlocked
            ? collect()
            : DeviceSyncCommand::query()
                ->where('device_id', $device->id)
                ->whereIn('status', ['pending', 'retry'])
                ->orderBy('created_at')
                ->limit($limit)
                ->get();

        $commands = $commands->map(fn (DeviceSyncCommand $command): array => [
            'id' => $command->id,
            'type' => $command->command_type,
            'payload' => $command->payload,
            'idempotencyKey' => $command->idempotency_key,
            'targetEntityType' => $command->target_entity_type,
            'targetEntityId' => $command->target_entity_id,
            'commandVersion' => $command->command_version,
            'attempts' => $command->attempts,
        ]);

        $checkpoint = DeviceCheckpoint::query()->where('device_id', $device->id)->first();
        $checkpointData = $checkpoint === null
            ? ['streamEpoch' => 0, 'lastNativeEventId' => 0, 'lastConfirmedAt' => null]
            : [
                'streamEpoch' => $checkpoint->stream_epoch,
                'lastNativeEventId' => $checkpoint->last_native_event_id,
                'lastConfirmedAt' => $checkpoint->last_confirmed_at?->toIso8601String(),
            ];

        return response()->json([
            'commands' => $commands,
            'checkpoint' => $checkpointData,
            // The vendor-specific identifier (e.g. a BioStar2 device id)
            // used for ADAPTER calls, distinct from this route's Laravel
            // device UUID used for calls back to this API — null for
            // simulator devices, which key off the Laravel UUID directly.
            'deviceIdentifier' => $device->device_identifier,
        ]);
    }

    public function acknowledge(
        AcknowledgeConnectorCommandRequest $request,
        string $deviceId,
        string $commandId,
        AcknowledgeDeviceSyncCommandAction $acknowledge,
    ): JsonResponse {
        $device = Device::query()->findOrFail($deviceId);
        $command = DeviceSyncCommand::query()->findOrFail($commandId);
        abort_unless($command->device_id === $device->id, 404);

        $result = $acknowledge->execute(
            $command,
            (string) $request->validated('result'),
            $request->validated('error'),
        );

        return response()->json([
            'commandId' => $result->id,
            'status' => $result->status,
            'attempts' => $result->attempts,
            'acknowledgedAt' => $result->acknowledged_at?->toIso8601String(),
        ]);
    }
}
