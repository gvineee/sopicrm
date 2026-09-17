<?php

namespace App\Domain\Devices\Services;

use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceSyncCommand;
use Illuminate\Database\Eloquent\Model;

/**
 * Spec section 6 explicit UI requirement: "UI-ში აჩვენე desired state და
 * თითოეულ მოწყობილობაზე acknowledged state ... Offline მოწყობილობაზე
 * გაუქმების დაჭერა არ გამოჩნდეს როგორც დასრულებული გაუქმება."
 *
 * "Desired" = the effect of the highest-`command_version` command ever
 * queued for a (device, target entity) pair. "Acknowledged" = the effect of
 * the highest-`command_version` command that actually reached `succeeded`.
 * When those two diverge, the entity is `isPending` — this is the ONLY
 * signal the UI is allowed to use to decide whether to render a revocation
 * as "complete"; it must never infer completion from `desired` alone.
 */
final class DeviceDesiredStateResolver
{
    /**
     * @return array{
     *   desired: string,
     *   acknowledged: string,
     *   is_pending: bool,
     *   latest_command_status: string|null,
     *   latest_command_version: int|null,
     *   acknowledged_command_version: int|null,
     * }
     */
    public function stateFor(Device $device, Model $targetEntity): array
    {
        $targetType = $targetEntity->getMorphClass();
        $targetId = $targetEntity->getKey();

        $latest = DeviceSyncCommand::query()
            ->where('device_id', $device->id)
            ->where('target_entity_type', $targetType)
            ->where('target_entity_id', $targetId)
            ->orderByDesc('command_version')
            ->first();

        $latestSucceeded = DeviceSyncCommand::query()
            ->where('device_id', $device->id)
            ->where('target_entity_type', $targetType)
            ->where('target_entity_id', $targetId)
            ->where('status', 'succeeded')
            ->orderByDesc('command_version')
            ->first();

        return [
            'desired' => $latest ? $this->effectOf($latest->command_type) : 'unknown',
            'acknowledged' => $latestSucceeded ? $this->effectOf($latestSucceeded->command_type) : 'not_synced',
            'is_pending' => $latest !== null
                && (! $latestSucceeded || $latestSucceeded->command_version < $latest->command_version),
            'latest_command_status' => $latest?->status,
            'latest_command_version' => $latest?->command_version,
            'acknowledged_command_version' => $latestSucceeded?->command_version,
        ];
    }

    private function effectOf(string $commandType): string
    {
        return match ($commandType) {
            'add_user', 'update_user', 'sync_access_group', 'sync_schedule' => 'granted',
            'revoke_credential' => 'revoked',
            default => 'unknown',
        };
    }
}
