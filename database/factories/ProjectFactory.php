<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Projects\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->unique()->streetName().' Site',
            'code' => strtoupper(fake()->unique()->bothify('PRJ-####')),
            'manager_user_id' => User::factory(),
            'address' => fake()->address(),
            'status' => 'active',
        ];
    }
}
