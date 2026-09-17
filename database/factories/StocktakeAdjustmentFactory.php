<?php

namespace Database\Factories;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\StocktakeAdjustment;
use App\Domain\Assets\Models\StocktakeLine;
use App\Domain\Auth\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StocktakeAdjustment>
 */
class StocktakeAdjustmentFactory extends Factory
{
    protected $model = StocktakeAdjustment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'stocktake_line_id' => StocktakeLine::factory(),
            'asset_id' => Asset::factory(),
            'adjustment_type' => 'confirmed_found',
            'approved_by_user_id' => User::factory(),
            'approved_at' => now(),
        ];
    }
}
