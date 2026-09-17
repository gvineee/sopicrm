<?php

namespace App\Domain\Devices\Services;

use App\Domain\Devices\Models\Device;
use Illuminate\Support\Carbon;

/**
 * Derives `devices.status` (online/offline/degraded/unknown) from heartbeat
 * age against configurable thresholds (config/modules/devices.php —
 * routine, non-hardware-specific operational thresholds, never a fabricated
 * device parameter; see docs/decisions.md). Deliberately independent of
 * `sync_status` — spec section 6: "Online არ ნიშნავს, რომ ყველა ბარათი
 * სინქრონიზებულია."
 */
final class DeviceStatusResolver
{
    public function statusFor(Device $device, ?Carbon $now = null): string
    {
        if ($device->last_heartbeat_at === null) {
            return 'unknown';
        }

        $now ??= Carbon::now();
        $ageSeconds = $device->last_heartbeat_at->diffInSeconds($now);

        if ($ageSeconds > (int) config('devices.heartbeat_offline_after_seconds')) {
            return 'offline';
        }

        if ($ageSeconds > (int) config('devices.heartbeat_degraded_after_seconds')) {
            return 'degraded';
        }

        return 'online';
    }
}
