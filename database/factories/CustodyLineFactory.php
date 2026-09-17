<?php

namespace Database\Factories;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\CustodyLine;
use App\Domain\Assets\Models\CustodyTransaction;
use App\Domain\Auth\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustodyLine>
 */
class CustodyLineFactory extends Factory
{
    protected $model = CustodyLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'custody_transaction_id' => CustodyTransaction::factory(),
            'asset_id' => Asset::factory(),
            'quantity' => 1,
            'returned_quantity' => 0,
        ];
    }
}
