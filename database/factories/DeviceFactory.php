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
            'name' => fake()->unique()->streetName().' Reader',
            'vendor' => 'suprema',
            'serial_number' => strtoupper(fake()->unique()->bothify('SN########')),
            'device_identifier' => null,
            'connection_mode' => 'gateway',
            'model' => 'XPASS2-XP2-MDPB',
            'reader_role' => 'in',
            'device_timezone' => 'Asia/Tbilisi',
            'timezone' => 'Asia/Tbilisi',
            'status' => 'unknown',
            'sync_status' => 'pending',
            'enabled' => true,
        ];
    }
}
