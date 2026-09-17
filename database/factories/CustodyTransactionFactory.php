<?php

namespace Database\Factories;

use App\Domain\Assets\Models\CustodyTransaction;
use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustodyTransaction>
 */
class CustodyTransactionFactory extends Factory
{
    protected $model = CustodyTransaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'type' => 'issue',
            'receiving_employee_id' => Employee::factory(),
            'occurred_at' => now(),
            'condition_at_transaction' => 'good',
            'photo_attachment_ids' => [],
            'issued_by_user_id' => User::factory(),
            'status' => 'issued',
        ];
    }
}
