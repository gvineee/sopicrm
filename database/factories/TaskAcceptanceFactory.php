<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Tasks\Models\TaskAcceptance;
use App\Domain\Tasks\Models\TaskSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskAcceptance>
 */
class TaskAcceptanceFactory extends Factory
{
    protected $model = TaskAcceptance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'task_submission_id' => TaskSubmission::factory(),
            'accepted_by_user_id' => User::factory(),
            'accepted_quantity' => 1,
            'accepted_at' => now(),
        ];
    }
}
