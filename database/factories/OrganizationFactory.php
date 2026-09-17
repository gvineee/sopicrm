<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'legal_name' => null,
            'default_currency' => 'GEL',
            'default_timezone' => 'Asia/Tbilisi',
            'is_active' => true,
        ];
    }
}
