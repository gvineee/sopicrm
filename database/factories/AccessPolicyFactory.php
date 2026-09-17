<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Models\AccessPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessPolicy>
 */
class AccessPolicyFactory extends Factory
{
    protected $model = AccessPolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->unique()->word().' '.fake()->word().' Policy',
            'site_ids' => [],
            'schedule_definition' => ['always' => true],
            'is_active' => true,
        ];
    }
}
