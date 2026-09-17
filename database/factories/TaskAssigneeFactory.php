<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskAssignee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskAssignee>
 */
class TaskAssigneeFactory extends Factory
{
    protected $model = TaskAssignee::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'task_id' => Task::factory(),
            'employee_id' => Employee::factory(),
        ];
    }
}
