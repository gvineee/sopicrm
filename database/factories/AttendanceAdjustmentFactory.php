<?php

namespace Database\Factories;

use App\Domain\Attendance\Models\AttendanceAdjustment;
use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceAdjustment>
 */
class AttendanceAdjustmentFactory extends Factory
{
    protected $model = AttendanceAdjustment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'employee_id' => Employee::factory(),
            'work_date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'reason' => fake()->sentence(),
            'requested_by_user_id' => User::factory(),
            'status' => 'pending',
        ];
    }
}
