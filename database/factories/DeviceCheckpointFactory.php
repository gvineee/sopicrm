<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceCheckpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceCheckpoint>
 */
class DeviceCheckpointFactory extends Factory
{
    protected $model = DeviceCheckpoint::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'device_id' => Device::factory(),
            'stream_epoch' => 0,
            'last_native_event_id' => 0,
            'last_confirmed_at' => now(),
        ];
    }
}
