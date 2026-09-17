<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Payroll\Models\DailyPayPolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyPayPolicy>
 */
class DailyPayPolicyFactory extends Factory
{
    protected $model = DailyPayPolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'full_day_threshold_minutes' => 480,
            'half_day_threshold_minutes' => 240,
            'minimum_attendance_minutes' => 60,
            'incomplete_day_behavior' => 'block',
            'max_day_units_per_work_date' => 1.00,
            'is_confirmed' => false,
            'confirmed_by_user_id' => null,
            'confirmed_at' => null,
        ];
    }

    /**
     * A policy an accountant has explicitly confirmed — the only state in
     * which CalculatePayRunAction will compute daily-basis lines.
     */
    public function confirmed(): static
    {
        return $this->state(fn () => [
            'is_confirmed' => true,
            'confirmed_by_user_id' => User::factory(),
            'confirmed_at' => now(),
        ]);
    }
}
