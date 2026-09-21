<?php

namespace Database\Factories;

use App\Domain\Attendance\Models\AttendanceIncrementalCheckpoint;
use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceIncrementalCheckpoint>
 */
class AttendanceIncrementalCheckpointFactory extends Factory
{
    protected $model = AttendanceIncrementalCheckpoint::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'employee_id' => Employee::factory(),
            'last_processed_at' => now(),
        ];
    }
}
