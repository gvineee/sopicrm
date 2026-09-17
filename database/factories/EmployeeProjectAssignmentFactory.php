<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\EmployeeProjectAssignment;
use App\Domain\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeProjectAssignment>
 */
class EmployeeProjectAssignmentFactory extends Factory
{
    protected $model = EmployeeProjectAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'employee_id' => Employee::factory(),
            'project_id' => Project::factory(),
            'starts_on' => fake()->dateTimeBetween('-6 months', 'now'),
            'ends_on' => null,
        ];
    }
}
