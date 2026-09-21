<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Shared\Models\Attachment;
use App\Domain\Timesheets\Models\TimesheetEmailDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimesheetEmailDelivery>
 */
class TimesheetEmailDeliveryFactory extends Factory
{
    protected $model = TimesheetEmailDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'timesheet_id' => TimesheetFactory::new(),
            'timesheet_version_at_send' => 1,
            'attachment_id' => Attachment::factory(),
            'recipient_email' => $this->faker->safeEmail(),
            'subject' => 'თქვენი ტაბელი',
            'status' => 'queued',
            'requested_by_user_id' => User::factory(),
            'created_at' => now(),
        ];
    }
}
