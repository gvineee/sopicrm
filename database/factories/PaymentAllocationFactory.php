<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Payroll\Models\Payment;
use App\Domain\Payroll\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAllocation>
 */
class PaymentAllocationFactory extends Factory
{
    protected $model = PaymentAllocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'payment_id' => Payment::factory(),
            'allocated_amount' => fake()->randomFloat(2, 10, 500),
            'allocation_type' => 'payment_to_earnings',
        ];
    }
}
