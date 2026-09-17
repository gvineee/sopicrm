<?php

namespace Database\Factories;

use App\Domain\Assets\Models\Asset;
use App\Domain\Auth\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->unique()->word().' '.fake()->word(),
            'category' => 'power_tool',
            'tracking_type' => 'individual',
            'inventory_code' => strtoupper(fake()->unique()->bothify('AST-####')),
            'condition' => 'good',
            'ownership' => 'owned',
            'qr_token' => (string) Str::uuid(),
        ];
    }
}
