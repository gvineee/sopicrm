<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Shared\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'recipient_user_id' => User::factory(),
            'type' => 'task_assigned',
            'payload' => ['message' => fake()->sentence()],
            'dedup_key' => (string) Str::uuid(),
            'read_at' => null,
        ];
    }
}
