<?php

namespace Database\Factories;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetActiveCustody;
use App\Domain\Auth\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetActiveCustody>
 */
class AssetActiveCustodyFactory extends Factory
{
    protected $model = AssetActiveCustody::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'asset_id' => Asset::factory(),
            'status' => 'available',
        ];
    }
}
