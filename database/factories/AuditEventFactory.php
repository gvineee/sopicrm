<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Shared\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AuditEvent>
 *
 * Added by the P0+P1 schema pass to complete factory coverage for this
 * pre-existing (Auth/RBAC pass) model — see docs/decisions.md.
 */
class AuditEventFactory extends Factory
{
    protected $model = AuditEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'actor_user_id' => User::factory(),
            'action' => 'test.action',
            'target_type' => 'test_target',
            'target_id' => (string) Str::uuid(),
            'created_at' => now(),
        ];
    }
}
