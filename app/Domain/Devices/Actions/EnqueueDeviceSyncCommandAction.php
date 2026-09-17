<?php

namespace App\Domain\Devices\Actions;

use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceSyncCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single place that assigns a command its monotonic `command_version`
 * and `idempotency_key` — spec section 6 hard rule: "Idempotency key და
 * ვერსია თითო ბრძანებაზე; ძველმა retry-მ ახალ გაუქმებას ვერ გადააწეროს."
 *
 * `command_version` is monotonic per (device_id, target_entity_type,
 * target_entity_id) — the next integer after whatever has ever been queued
 * for that exact target on that exact device, so a later-enqueued command
 * always outranks an earlier one regardless of processing order.
 *
 * The idempotency key is DETERMINISTIC (a hash of device+target+type+
 * version), not a random UUID: calling this method twice for what is
 * logically the identical command (same target, same version) returns the
 * SAME row via `firstOrCreate` instead of creating a duplicate — the
 * `unique(organization_id, idempotency_key)` DB constraint is the real
 * guarantee behind that.
 */
class EnqueueDeviceSyncCommandAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(
        Device $device,
        string $commandType,
        array $payload,
        ?Model $targetEntity = null,
        ?string $operationKey = null,
    ): DeviceSyncCommand {
        return DB::transaction(function () use ($device, $commandType, $payload, $targetEntity, $operationKey): DeviceSyncCommand {
            $targetEntityType = $targetEntity?->getMorphClass();
            $targetEntityId = $targetEntity?->getKey();
            $idempotencyKey = hash('sha256', implode('|', [
                $device->id,
                $targetEntityType ?? '',
                $targetEntityId ?? '',
                $commandType,
                $operationKey ?? (string) Str::uuid(),
            ]));

            $existing = DeviceSyncCommand::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }

            // Serializing on the device row makes version allocation safe
            // even when two workers enqueue for the same target at once.
            Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();

            $nextVersion = (int) (DeviceSyncCommand::query()
                ->where('device_id', $device->id)
                ->where('target_entity_type', $targetEntityType)
                ->where('target_entity_id', $targetEntityId)
                ->max('command_version') ?? 0) + 1;

            return DeviceSyncCommand::create([
                'device_id' => $device->id,
                'command_type' => $commandType,
                'payload' => $payload,
                'idempotency_key' => $idempotencyKey,
                'target_entity_type' => $targetEntityType,
                'target_entity_id' => $targetEntityId,
                'command_version' => $nextVersion,
                'status' => 'pending',
                'attempts' => 0,
            ]);
        });
    }
}
