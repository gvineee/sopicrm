<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Payroll\Models\PayAdjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayAdjustment>
 */
class PayAdjustmentFactory extends Factory
{
    protected $model = PayAdjustment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'employee_id' => Employee::factory(),
            'type' => 'bonus',
            'amount' => fake()->randomFloat(2, 10, 200),
            'reason' => fake()->sentence(),
            'approved_by_user_id' => User::factory(),
        ];
    }
}
