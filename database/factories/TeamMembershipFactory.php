<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\Team;
use App\Domain\Employees\Models\TeamMembership;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamMembership>
 */
class TeamMembershipFactory extends Factory
{
    protected $model = TeamMembership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'team_id' => Team::factory(),
            'employee_id' => Employee::factory(),
            'started_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'ended_at' => null,
        ];
    }
}
