<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Shared\Models\IdempotencyRecord;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IdempotencyRecord>
 *
 * Added by the P0+P1 schema pass to complete factory coverage for this
 * pre-existing (Auth/RBAC pass) model — see docs/decisions.md.
 */
class IdempotencyRecordFactory extends Factory
{
    protected $model = IdempotencyRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'endpoint_signature' => 'POST test.route',
            'request_hash' => hash('sha256', (string) Str::uuid()),
            'response_status' => 'in_progress',
            'created_at' => now(),
        ];
    }
}
