<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceCapability;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceCapability>
 */
class DeviceCapabilityFactory extends Factory
{
    protected $model = DeviceCapability::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'device_id' => Device::factory(),
            'capability_key' => 'max_users',
            'capability_value' => ['limit' => fake()->numberBetween(1000, 50000)],
            'read_at' => now(),
        ];
    }
}
