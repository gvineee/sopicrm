<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskSubmission>
 */
class TaskSubmissionFactory extends Factory
{
    protected $model = TaskSubmission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'task_id' => Task::factory(),
            'submitted_by_employee_id' => Employee::factory(),
            'photo_attachment_ids' => [],
            'submitted_at' => now(),
            'status' => 'pending_review',
        ];
    }
}
