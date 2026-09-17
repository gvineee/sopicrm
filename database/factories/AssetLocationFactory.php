<?php

namespace Database\Factories;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetLocation;
use App\Domain\Auth\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AssetLocation>
 */
class AssetLocationFactory extends Factory
{
    protected $model = AssetLocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'locatable_type' => 'warehouse',
            'locatable_id' => (string) Str::uuid(),
            'asset_id' => Asset::factory(),
            'as_of' => now(),
            'is_current' => true,
        ];
    }
}
