<?php

namespace Database\Factories;

use App\Domain\Attendance\Models\ShiftTemplate;
use App\Domain\Auth\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShiftTemplate>
 */
class ShiftTemplateFactory extends Factory
{
    protected $model = ShiftTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => 'Day Shift',
            'starts_at_local' => '08:00:00',
            'ends_at_local' => '17:00:00',
            'crosses_midnight' => false,
            'scheduled_days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
            'break_policy' => ['type' => 'fixed', 'minutes' => 60],
            'allowed_late_minutes' => 10,
            'rounding_policy' => ['mode' => 'none'],
        ];
    }
}
