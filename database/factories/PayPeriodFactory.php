<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Payroll\Models\PayPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayPeriod>
 */
class PayPeriodFactory extends Factory
{
    protected $model = PayPeriod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-2 months', '-1 month');
        $end = (clone $start)->modify('+1 month -1 day');

        return [
            'organization_id' => Organization::factory(),
            'starts_on' => $start->format('Y-m-d'),
            'ends_on' => $end->format('Y-m-d'),
            'status' => 'open',
        ];
    }
}
