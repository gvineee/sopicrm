<?php

namespace Database\Factories;

use App\Domain\Attendance\Models\Timesheet;
use App\Domain\Attendance\Models\TimesheetLine;
use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\RateHistory;
use App\Domain\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimesheetLine>
 */
class TimesheetLineFactory extends Factory
{
    protected $model = TimesheetLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'timesheet_id' => Timesheet::factory(),
            'work_date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'project_id' => Project::factory(),
            'payable_minutes' => 480,
            'rate_type' => 'hourly',
            'rate_snapshot_id' => RateHistory::factory(),
        ];
    }
}
