<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Models\Credential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Credential>
 */
class CredentialFactory extends Factory
{
    protected $model = Credential::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'card_type' => 'EM',
            'canonical_identifier' => (string) fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'bit_length' => 26,
            'leading_zeros_preserved' => true,
            'status' => 'unassigned',
        ];
    }
}
