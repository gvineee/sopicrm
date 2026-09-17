<?php

namespace Database\Factories;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetIncident;
use App\Domain\Auth\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetIncident>
 */
class AssetIncidentFactory extends Factory
{
    protected $model = AssetIncident::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'asset_id' => Asset::factory(),
            'incident_type' => 'damage',
            'occurred_at' => now(),
            'description' => fake()->sentence(),
            'photo_attachment_ids' => [],
            'reported_by_user_id' => User::factory(),
        ];
    }
}
