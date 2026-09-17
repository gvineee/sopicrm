<?php

namespace Database\Factories;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetKit;
use App\Domain\Auth\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetKit>
 */
class AssetKitFactory extends Factory
{
    protected $model = AssetKit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'kit_asset_id' => Asset::factory(),
            'component_asset_id' => Asset::factory(),
            'quantity' => 1,
        ];
    }
}
