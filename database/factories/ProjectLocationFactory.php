<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectLocation>
 */
class ProjectLocationFactory extends Factory
{
    protected $model = ProjectLocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'project_id' => Project::factory(),
            'level_type' => 'floor',
            'name' => 'Floor '.fake()->numberBetween(1, 20),
        ];
    }
}
