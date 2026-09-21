<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Models\Door;
use App\Domain\Devices\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Door>
 */
class DoorFactory extends Factory
{
    protected $model = Door::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'site_id' => Site::factory(),
            'zone_id' => null,
            'device_id' => null,
            'name' => fake()->unique()->streetName().' Door',
            'direction' => 'unspecified',
            'enabled' => true,
        ];
    }
}
