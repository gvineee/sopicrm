<?php

namespace Database\Factories;

use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Attendance\Models\AttendanceSessionBreakDeduction;
use App\Domain\Attendance\Models\ShiftTemplate;
use App\Domain\Auth\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceSessionBreakDeduction>
 */
class AttendanceSessionBreakDeductionFactory extends Factory
{
    protected $model = AttendanceSessionBreakDeduction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'attendance_session_id' => AttendanceSession::factory(),
            'shift_template_id' => ShiftTemplate::factory(),
            'break_window_key' => 'lunch',
            'deducted_minutes' => 60,
        ];
    }
}
