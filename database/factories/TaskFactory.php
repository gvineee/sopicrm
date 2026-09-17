<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'project_id' => Project::factory(),
            'title' => fake()->sentence(4),
            'accountable_owner_employee_id' => Employee::factory(),
            'priority' => 'normal',
            'accepted_quantity' => 0,
            'status' => 'draft',
            'self_close_allowed' => false,
        ];
    }
}
