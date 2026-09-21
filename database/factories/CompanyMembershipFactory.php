<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Companies\Models\Company;
use App\Domain\Companies\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CompanyMembership> */
class CompanyMembershipFactory extends Factory
{
    protected $model = CompanyMembership::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'company_id' => fn (array $attributes) => Company::factory()->create([
                'organization_id' => $attributes['organization_id'],
            ])->id,
            'user_id' => fn (array $attributes) => User::factory()->create([
                'organization_id' => $attributes['organization_id'],
                'current_organization_id' => $attributes['organization_id'],
            ])->id,
            'is_primary' => false,
        ];
    }
}
