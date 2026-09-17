<?php

namespace Database\Factories;

use App\Domain\Assets\Models\Stocktake;
use App\Domain\Auth\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Stocktake>
 */
class StocktakeFactory extends Factory
{
    protected $model = Stocktake::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'scope_type' => 'site',
            'scope_id' => (string) Str::uuid(),
            'session_started_at' => now(),
            'expected_snapshot' => [],
            'status' => 'in_progress',
            'performed_by_user_id' => User::factory(),
        ];
    }
}
