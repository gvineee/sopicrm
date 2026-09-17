<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskDependency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskDependency>
 */
class TaskDependencyFactory extends Factory
{
    protected $model = TaskDependency::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'task_id' => Task::factory(),
            'depends_on_task_id' => Task::factory(),
        ];
    }
}
