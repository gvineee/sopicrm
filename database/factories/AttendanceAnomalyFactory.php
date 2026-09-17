<?php

namespace Database\Factories;

use App\Domain\Attendance\Models\AttendanceAnomaly;
use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceAnomaly>
 */
class AttendanceAnomalyFactory extends Factory
{
    protected $model = AttendanceAnomaly::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'employee_id' => Employee::factory(),
            'anomaly_type' => 'missing_out',
            'detected_at' => now(),
            'details' => [],
        ];
    }
}
