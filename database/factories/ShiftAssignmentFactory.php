<?php

namespace Database\Factories;

use App\Domain\Attendance\Models\ShiftAssignment;
use App\Domain\Attendance\Models\ShiftTemplate;
use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShiftAssignment>
 */
class ShiftAssignmentFactory extends Factory
{
    protected $model = ShiftAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'employee_id' => Employee::factory(),
            'shift_template_id' => ShiftTemplate::factory(),
            'effective_from' => fake()->dateTimeBetween('-6 months', 'now'),
        ];
    }
}
