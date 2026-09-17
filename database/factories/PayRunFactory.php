<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Payroll\Models\PayPeriod;
use App\Domain\Payroll\Models\PayRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayRun>
 */
class PayRunFactory extends Factory
{
    protected $model = PayRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'pay_period_id' => PayPeriod::factory(),
            'status' => 'draft',
        ];
    }
}
