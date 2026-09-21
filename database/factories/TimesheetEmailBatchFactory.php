<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Timesheets\Models\TimesheetEmailBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimesheetEmailBatch>
 */
class TimesheetEmailBatchFactory extends Factory
{
    protected $model = TimesheetEmailBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'mode' => 'per_employee',
            'status' => 'pending',
            'requested_by_user_id' => User::factory(),
            'total_count' => 0,
            'created_at' => now(),
        ];
    }
}
