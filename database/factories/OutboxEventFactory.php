<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Shared\Models\OutboxEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OutboxEvent>
 *
 * Added by the P0+P1 schema pass to complete factory coverage for this
 * pre-existing (Auth/RBAC pass) model — see docs/decisions.md.
 */
class OutboxEventFactory extends Factory
{
    protected $model = OutboxEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'event_type' => 'test.event',
            'subject_type' => 'test_subject',
            'subject_id' => (string) Str::uuid(),
            'payload' => [],
            'available_at' => now(),
        ];
    }
}
