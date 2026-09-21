<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Companies\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Company> */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->unique()->company(),
            'legal_name' => null,
            'code' => strtoupper(fake()->unique()->bothify('CMP-####')),
            'default_currency' => 'GEL',
            'default_timezone' => 'Asia/Tbilisi',
            'is_active' => true,
        ];
    }
}
