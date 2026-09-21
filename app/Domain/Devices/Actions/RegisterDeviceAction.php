<?php

namespace App\Domain\Devices\Actions;

use App\Domain\Devices\Contracts\DeviceAdapterInterface;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceCapability;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;

/**
 * Spec section 6 device fields: organization/site/serial/model/firmware/
 * address/reader role/timezone. Capability snapshot is read live from the
 * bound adapter at registration time (never hardcoded) and re-readable
 * later via `refreshCapabilities()`.
 */
class RegisterDeviceAction
{
    public function __construct(
        private readonly DeviceAdapterInterface $adapter,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data, ?User $actor = null): Device
    {
        $device = Device::create([
            'site_id' => $data['site_id'],
            'name' => $data['name'] ?? $data['serial_number'],
            'vendor' => $data['vendor'] ?? 'suprema',
            'serial_number' => $data['serial_number'],
            'device_identifier' => $data['device_identifier'] ?? null,
            'ip_address' => $data['ip_address'] ?? null,
            'port' => $data['port'] ?? null,
            'mac_address' => $data['mac_address'] ?? null,
            'model' => $data['model'],
            'firmware_version' => $data['firmware_version'] ?? null,
            'hardware_version' => $data['hardware_version'] ?? null,
            'connection_mode' => $data['connection_mode'] ?? 'gateway',
            'install_location' => $data['install_location'] ?? null,
            'reader_role' => $data['reader_role'] ?? 'unspecified',
            'device_timezone' => $data['timezone'] ?? $data['device_timezone'] ?? 'Asia/Tbilisi',
            'timezone' => $data['timezone'] ?? $data['device_timezone'] ?? 'Asia/Tbilisi',
            'status' => 'unknown',
            'sync_status' => 'pending',
            'connector_version' => null,
            'enabled' => $data['enabled'] ?? true,
        ]);

        $this->audit->log(action: 'devices.device.registered', target: $device, after: $device->only([
            'site_id', 'serial_number', 'model', 'reader_role',
        ]), actor: $actor);

        $this->refreshCapabilities($device);

        return $device;
    }

    public function refreshCapabilities(Device $device): void
    {
        $capabilities = $this->adapter->readCapabilities($device);
        $readAt = now();

        foreach ($capabilities as $key => $value) {
            DeviceCapability::query()->updateOrCreate(
                ['device_id' => $device->id, 'capability_key' => $key],
                ['capability_value' => is_array($value) ? $value : ['value' => $value], 'read_at' => $readAt],
            );
        }
    }
}
