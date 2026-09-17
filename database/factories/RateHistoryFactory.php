<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\RateHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RateHistory>
 */
class RateHistoryFactory extends Factory
{
    protected $model = RateHistory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'employee_id' => Employee::factory(),
            'project_id' => null,
            'rate_type' => 'hourly',
            'amount' => fake()->randomFloat(2, 8, 40),
            'currency' => 'GEL',
            'effective_from' => fake()->dateTimeBetween('-1 year', 'now'),
            'effective_to' => null,
            'approved_by_user_id' => User::factory(),
        ];
    }
}
