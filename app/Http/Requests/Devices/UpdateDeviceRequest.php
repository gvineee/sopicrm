<?php

namespace App\Http\Requests\Devices;

use App\Domain\Devices\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $device = $this->route('device');
        $deviceId = $device instanceof Device ? $device->id : null;
        $organizationId = $this->user()->organization_id;

        return [
            'name' => ['nullable', 'string', 'max:255'],
            'vendor' => ['nullable', 'string', 'max:80'],
            'site_id' => ['required', 'uuid', Rule::exists('sites', 'id')->where('organization_id', $organizationId)],
            'serial_number' => [
                'required', 'string', 'max:100',
                Rule::unique('devices', 'serial_number')->where('organization_id', $organizationId)->ignore($deviceId),
            ],
            'device_identifier' => [
                'nullable', 'string', 'max:150',
                Rule::unique('devices', 'device_identifier')->where('organization_id', $organizationId)->ignore($deviceId),
            ],
            'ip_address' => ['nullable', 'ip'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'mac_address' => ['nullable', 'string', 'max:17', 'regex:/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/'],
            'model' => ['required', 'string', 'max:100'],
            'firmware_version' => ['nullable', 'string', 'max:50'],
            'hardware_version' => ['nullable', 'string', 'max:50'],
            'connection_mode' => ['nullable', Rule::in(['gateway', 'tcp', 'udp', 'other'])],
            'install_location' => ['nullable', 'string', 'max:255'],
            'reader_role' => ['required', Rule::in(['in', 'out', 'unspecified'])],
            'device_timezone' => ['nullable', 'timezone:all'],
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
            'reader_role' => (string) $this->validated('reader_role'),
            'device_timezone' => (string) ($this->nullableString('device_timezone') ?? $this->nullableString('timezone') ?? 'UTC'),
            'timezone' => $this->nullableString('timezone') ?? $this->nullableString('device_timezone') ?? 'UTC',
            'enabled' => $this->has('enabled') ? (bool) $this->validated('enabled') : true,
        ];
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
