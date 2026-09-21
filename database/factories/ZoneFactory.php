<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Models\Site;
use App\Domain\Devices\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Zone>
 */
class ZoneFactory extends Factory
{
    protected $model = Zone::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'site_id' => Site::factory(),
            'name' => fake()->unique()->streetName().' Zone',
            'description' => null,
            'enabled' => true,
        ];
    }
}
