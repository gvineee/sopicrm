<?php

namespace App\Domain\Devices\Actions;

use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceSyncCommand;
use Illuminate\Database\Eloquent\Model;

/**
 * Spec section 6: "Reconciliation შეადარებს სასურველ და რეალურ მდგომარეობას;
 * bulk destructive reset დაუშვებელია ჩვეულებრივი სინქრონიზაციისას."
 *
 * For every target entity this device has EVER had a command queued for,
 * compares the latest desired command_version against the latest
 * SUCCEEDED command_version. If they diverge and nothing is already
 * in-flight for that target, it enqueues exactly one corrective re-push of
 * that target's latest intended command via
 * App\Domain\Devices\Actions\EnqueueDeviceSyncCommandAction — never a
 * device-wide "wipe and resend everything" operation. This keeps the
 * corrective action scoped and auditable per entity, exactly the "no bulk
 * destructive reset" guarantee the spec calls for.
 */
class ReconcileDeviceStateAction
{
    public function __construct(private readonly EnqueueDeviceSyncCommandAction $enqueue) {}

    /**
     * @return array{checked: int, corrected: int}
     */
    public function execute(Device $device): array
    {
        $targets = DeviceSyncCommand::query()
            ->where('device_id', $device->id)
            ->whereNotNull('target_entity_type')
            ->select('target_entity_type', 'target_entity_id')
            ->distinct()
            ->get();

        $checked = 0;
        $corrected = 0;

        foreach ($targets as $target) {
            $checked++;

            $latest = DeviceSyncCommand::query()
                ->where('device_id', $device->id)
                ->where('target_entity_type', $target->target_entity_type)
                ->where('target_entity_id', $target->target_entity_id)
                ->orderByDesc('command_version')
                ->first();

            if ($latest === null) {
                continue;
            }

            $latestSucceededVersion = DeviceSyncCommand::query()
                ->where('device_id', $device->id)
                ->where('target_entity_type', $target->target_entity_type)
                ->where('target_entity_id', $target->target_entity_id)
                ->where('status', 'succeeded')
                ->max('command_version');

            $isReconciled = $latestSucceededVersion !== null && $latestSucceededVersion === $latest->command_version;

            $hasInFlight = DeviceSyncCommand::query()
                ->where('device_id', $device->id)
                ->where('target_entity_type', $target->target_entity_type)
                ->where('target_entity_id', $target->target_entity_id)
                ->whereIn('status', ['pending', 'processing', 'retry'])
                ->exists();

            if (! $isReconciled && ! $hasInFlight) {
                $targetModel = $this->resolveTargetModel($target->target_entity_type, $target->target_entity_id);

                if ($targetModel !== null) {
                    $this->enqueue->execute($device, $latest->command_type, $latest->payload, $targetModel);
                    $corrected++;
                }
            }
        }

        $device->update([
            'sync_status' => $corrected > 0 ? 'pending' : ($checked > 0 ? 'in_sync' : $device->sync_status),
        ]);

        return ['checked' => $checked, 'corrected' => $corrected];
    }

    private function resolveTargetModel(string $type, string $id): ?Model
    {
        if (! class_exists($type) || ! is_subclass_of($type, Model::class)) {
            return null;
        }

        return $type::query()->find($id);
    }
}
