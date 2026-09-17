<?php

namespace Database\Factories;

use App\Domain\Attendance\Models\Timesheet;
use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Payroll\Models\PayPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Timesheet>
 */
class TimesheetFactory extends Factory
{
    protected $model = Timesheet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'employee_id' => Employee::factory(),
            'pay_period_id' => PayPeriod::factory(),
            'status' => 'draft',
        ];
    }
}
