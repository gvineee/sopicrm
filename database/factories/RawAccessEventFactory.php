<?php

namespace Database\Factories;

use App\Domain\Attendance\Models\RawAccessEvent;
use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RawAccessEvent>
 */
class RawAccessEventFactory extends Factory
{
    protected $model = RawAccessEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $time = fake()->dateTimeBetween('-1 week', 'now');

        return [
            'organization_id' => Organization::factory(),
            'device_id' => Device::factory(),
            'native_event_id' => fake()->unique()->numberBetween(1, 1_000_000_000),
            'stream_epoch' => 0,
            'raw_device_time' => $time,
            'normalized_event_time_utc' => $time,
            'received_at' => $time,
            'event_code' => 'access_granted',
            'reader_direction_snapshot' => 'in',
            'payload' => [],
            'ingestion_source' => 'device-connector',
        ];
    }
}
