<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\RateHistory;
use App\Domain\Payroll\Models\PayRun;
use App\Domain\Payroll\Models\PayRunLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayRunLine>
 */
class PayRunLineFactory extends Factory
{
    protected $model = PayRunLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = 8;
        $rate = 15;
        $gross = $quantity * $rate;

        return [
            'organization_id' => Organization::factory(),
            'pay_run_id' => PayRun::factory(),
            'employee_id' => Employee::factory(),
            'basis' => 'hourly',
            'quantity' => $quantity,
            'rate_snapshot_id' => RateHistory::factory(),
            'formula_applied' => "{$quantity}h x {$rate} GEL",
            'gross_amount' => $gross,
            'adjustments_amount' => 0,
            'net_amount' => $gross,
        ];
    }
}
