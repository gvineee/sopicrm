<?php

namespace App\Http\Requests\Devices;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * spec section 6 device registration fields. `organization_id`/`status`/
 * `sync_status` are never accepted from the client —
 * App\Domain\Devices\Actions\RegisterDeviceAction derives them server-side.
 */
class StoreDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Real Policy check (DevicePolicy::create) runs in the controller
        // before the Action executes — this only validates shape.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;

        return [
            'name' => ['nullable', 'string', 'max:255'],
            'vendor' => ['nullable', 'string', 'max:80'],
            'site_id' => [
                'required', 'uuid',
                Rule::exists('sites', 'id')->where('organization_id', $organizationId),
            ],
            'serial_number' => [
                'required', 'string', 'max:100',
                Rule::unique('devices', 'serial_number')->where('organization_id', $organizationId),
            ],
            'device_identifier' => [
                'nullable', 'string', 'max:150',
                Rule::unique('devices', 'device_identifier')->where('organization_id', $organizationId),
            ],
            'ip_address' => ['nullable', 'ip'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'mac_address' => ['nullable', 'string', 'max:17', 'regex:/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/'],
            'model' => ['required', 'string', 'max:100'],
            'firmware_version' => ['nullable', 'string', 'max:50'],
            'hardware_version' => ['nullable', 'string', 'max:50'],
            'connection_mode' => ['nullable', Rule::in(['gateway', 'tcp', 'udp', 'other'])],
            'install_location' => ['nullable', 'string', 'max:255'],
            'reader_role' => ['nullable', Rule::in(['in', 'out', 'unspecified'])],
            'device_timezone' => ['nullable', 'string', 'max:64'],
            'timezone' => ['nullable', 'timezone:all'],
            'enabled' => ['nullable', 'boolean'],
        ];
    }

    /** @return array{site_id:string,name:string|null,vendor:string,serial_number:string,device_identifier:string|null,ip_address:string|null,port:int|null,mac_address:string|null,model:string,firmware_version:string|null,hardware_version:string|null,connection_mode:string,install_location:string|null,reader_role:string,device_timezone:string,timezone:string,enabled:bool} */
    public function deviceData(): array
    {
        return [
            'name' => $this->nullableString('name'),
            'vendor' => $this->nullableString('vendor') ?? 'suprema',
            'site_id' => (string) $this->validated('site_id'),
            'serial_number' => (string) $this->validated('serial_number'),
            'device_identifier' => $this->nullableString('device_identifier'),
            'ip_address' => $this->nullableString('ip_address'),
            'port' => $this->validated('port') !== null ? (int) $this->validated('port') : null,
            'mac_address' => $this->nullableString('mac_address'),
            'model' => (string) $this->validated('model'),
            'firmware_version' => $this->nullableString('firmware_version'),
            'hardware_version' => $this->nullableString('hardware_version'),
            'connection_mode' => $this->nullableString('connection_mode') ?? 'gateway',
            'install_location' => $this->nullableString('install_location'),
            'reader_role' => $this->nullableString('reader_role') ?? 'unspecified',
            'device_timezone' => $this->nullableString('device_timezone') ?? 'Asia/Tbilisi',
            'timezone' => $this->nullableString('timezone') ?? $this->nullableString('device_timezone') ?? 'Asia/Tbilisi',
            'enabled' => $this->validated('enabled') === null ? true : (bool) $this->validated('enabled'),
        ];
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
