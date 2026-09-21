<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Shared\Models\Attachment;
use App\Domain\Timesheets\Models\TimesheetEmailDeliveryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimesheetEmailDeliveryItem>
 */
class TimesheetEmailDeliveryItemFactory extends Factory
{
    protected $model = TimesheetEmailDeliveryItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'delivery_id' => TimesheetEmailDeliveryFactory::new(),
            'timesheet_id' => TimesheetFactory::new(),
            'timesheet_version_at_send' => 1,
            'attachment_id' => Attachment::factory(),
            'created_at' => now(),
        ];
    }
}
