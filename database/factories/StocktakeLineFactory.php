<?php

namespace Database\Factories;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\Stocktake;
use App\Domain\Assets\Models\StocktakeLine;
use App\Domain\Auth\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StocktakeLine>
 */
class StocktakeLineFactory extends Factory
{
    protected $model = StocktakeLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'stocktake_id' => Stocktake::factory(),
            'asset_id' => Asset::factory(),
            'expected_quantity' => 1,
        ];
    }
}
