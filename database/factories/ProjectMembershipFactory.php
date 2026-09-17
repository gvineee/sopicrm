<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Auth\Models\ProjectMembership;
use App\Domain\Projects\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectMembership>
 */
class ProjectMembershipFactory extends Factory
{
    protected $model = ProjectMembership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'role_context' => 'member',
            'removed_at' => null,
        ];
    }
}
