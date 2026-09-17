<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'site_id' => Site::factory(),
            'serial_number' => strtoupper(fake()->unique()->bothify('SN########')),
            'model' => 'XPASS2-XP2-MDPB',
            'reader_role' => 'in',
            'device_timezone' => 'Asia/Tbilisi',
            'status' => 'unknown',
            'sync_status' => 'pending',
        ];
    }
}
