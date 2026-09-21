<?php

namespace App\Http\Resources\Devices;

use App\Domain\Devices\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * spec section 6 device registry fields for the Devices web UI. `status`
 * here is the raw stored column; the *resolved* (heartbeat-derived) status
 * shown to operators comes separately from
 * App\Domain\Devices\Services\DeviceStatusResolver so there is exactly one
 * place that turns heartbeat age into an online/offline/degraded/unknown
 * label.
 *
 * @mixin Device
 */
class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Device $device */
        $device = $this->resource;

        return [
            'id' => $device->id,
            'site_id' => $device->site_id,
            'name' => $device->name,
            'vendor' => $device->vendor,
            'site_name' => $this->whenLoaded('site', fn () => $device->site?->name),
            'serial_number' => $device->serial_number,
            'device_identifier' => $device->device_identifier,
            'ip_address' => $device->ip_address,
            'port' => $device->port,
            'mac_address' => $device->mac_address,
            'model' => $device->model,
            'firmware_version' => $device->firmware_version,
            'hardware_version' => $device->hardware_version,
            'connection_mode' => $device->connection_mode,
            'install_location' => $device->install_location,
            'reader_role' => $device->reader_role,
            'device_timezone' => $device->device_timezone,
            'timezone' => $device->timezone ?: $device->device_timezone,
            'status' => $device->status,
            'sync_status' => $device->sync_status,
            'last_heartbeat_at' => $device->last_heartbeat_at?->toIso8601String(),
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'last_event_at' => $device->last_event_at?->toIso8601String(),
            'last_sync_at' => $device->last_sync_at?->toIso8601String(),
            'connector_version' => $device->connector_version,
            'metadata' => $device->metadata,
            'enabled' => $device->enabled,
            'created_at' => $device->created_at?->toIso8601String(),
        ];
    }
}
