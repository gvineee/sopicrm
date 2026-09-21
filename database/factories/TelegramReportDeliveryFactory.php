<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Notifications\Models\TelegramLink;
use App\Domain\Notifications\Models\TelegramReportDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramReportDelivery>
 */
class TelegramReportDeliveryFactory extends Factory
{
    protected $model = TelegramReportDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'telegram_link_id' => TelegramLink::factory(),
            'requested_by_user_id' => User::factory(),
            'report_type' => 'attendance_summary',
            'status' => 'queued',
            'created_at' => now(),
        ];
    }
}
