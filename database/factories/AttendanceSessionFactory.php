<?php

namespace Database\Factories;

use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Models\Site;
use App\Domain\Employees\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceSession>
 */
class AttendanceSessionFactory extends Factory
{
    protected $model = AttendanceSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $clockIn = fake()->dateTimeBetween('-1 week', '-1 day');
        $clockOut = (clone $clockIn)->modify('+8 hours');

        return [
            'organization_id' => Organization::factory(),
            'employee_id' => Employee::factory(),
            'site_id' => Site::factory(),
            'clock_in_at' => $clockIn,
            'clock_out_at' => $clockOut,
            'work_date' => $clockIn->format('Y-m-d'),
            'raw_duration_minutes' => 480,
            'payable_minutes' => 420,
            'status' => 'closed',
        ];
    }
}
