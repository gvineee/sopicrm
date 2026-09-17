<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Payroll\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'employee_id' => Employee::factory(),
            'paid_at' => now(),
            'amount' => fake()->randomFloat(2, 100, 2000),
            'currency' => 'GEL',
            'method' => 'bank_transfer',
            'status' => 'pending',
        ];
    }
}
