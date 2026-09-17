<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Payroll\Models\Advance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Advance>
 */
class AdvanceFactory extends Factory
{
    protected $model = Advance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'employee_id' => Employee::factory(),
            'amount' => fake()->randomFloat(2, 50, 500),
            'currency' => 'GEL',
            'granted_at' => now(),
            'granted_by_user_id' => User::factory(),
            'reason' => fake()->sentence(),
            'status' => 'outstanding',
        ];
    }
}
