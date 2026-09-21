<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Notifications\Models\TelegramLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TelegramLink>
 */
class TelegramLinkFactory extends Factory
{
    protected $model = TelegramLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'telegram_chat_id' => null,
            'link_code' => Str::upper(Str::random(8)),
            'link_code_expires_at' => now()->addMinutes(15),
            'linked_at' => null,
        ];
    }

    public function linked(): static
    {
        return $this->state(fn (): array => [
            'telegram_chat_id' => (string) $this->faker->numberBetween(100000000, 999999999),
            'linked_at' => now(),
        ]);
    }
}
