<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Auth\Models\UserPermissionDenial;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPermissionDenial>
 */
class UserPermissionDenialFactory extends Factory
{
    protected $model = UserPermissionDenial::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'permission_name' => 'finance.access',
            'reason' => $this->faker->sentence(),
            'created_by_user_id' => User::factory(),
            'created_at' => now(),
        ];
    }
}
