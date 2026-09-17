<?php

namespace Database\Factories;

use App\Domain\Assets\Models\Acknowledgement;
use App\Domain\Assets\Models\CustodyTransaction;
use App\Domain\Auth\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Acknowledgement>
 */
class AcknowledgementFactory extends Factory
{
    protected $model = Acknowledgement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'custody_transaction_id' => CustodyTransaction::factory(),
            'acknowledged_by_user_id' => User::factory(),
            'acknowledged_at' => now(),
            'role_at_time' => 'receiver',
        ];
    }
}
