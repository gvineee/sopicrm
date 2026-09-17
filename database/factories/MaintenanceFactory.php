<?php

namespace Database\Factories;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\Maintenance;
use App\Domain\Auth\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Maintenance>
 */
class MaintenanceFactory extends Factory
{
    protected $model = Maintenance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'asset_id' => Asset::factory(),
            'vendor' => fake()->company(),
        ];
    }
}
