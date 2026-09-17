<?php

namespace App\Http\Controllers\Api\V1\Devices;

use App\Domain\Devices\Actions\AcknowledgeDeviceSyncCommandAction;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceSyncCommand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Devices\AcknowledgeConnectorCommandRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConnectorCommandController extends Controller
{
    public function index(Request $request, string $deviceId): JsonResponse
    {
        $device = Device::query()->findOrFail($deviceId);
        $limit = max(1, min((int) $request->query('limit', 100), 500));

        $commands = DeviceSyncCommand::query()
            ->where('device_id', $device->id)
            ->whereIn('status', ['pending', 'retry'])
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (DeviceSyncCommand $command): array => [
                'id' => $command->id,
                'type' => $command->command_type,
                'payload' => $command->payload,
                'idempotencyKey' => $command->idempotency_key,
                'targetEntityType' => $command->target_entity_type,
                'targetEntityId' => $command->target_entity_id,
                'commandVersion' => $command->command_version,
                'attempts' => $command->attempts,
            ]);

        return response()->json(['commands' => $commands]);
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
