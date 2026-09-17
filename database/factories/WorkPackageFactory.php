<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\WorkPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkPackage>
 */
class WorkPackageFactory extends Factory
{
    protected $model = WorkPackage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'project_id' => Project::factory(),
            'name' => fake()->unique()->word().' '.fake()->word().' '.fake()->word(),
        ];
    }
}
