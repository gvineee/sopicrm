<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\DailyJournal\Models\DailyReport;
use App\Domain\DailyJournal\Models\DailyReportTaskLink;
use App\Domain\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyReportTaskLink>
 */
class DailyReportTaskLinkFactory extends Factory
{
    protected $model = DailyReportTaskLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'daily_report_id' => DailyReport::factory(),
            'task_id' => Task::factory(),
            'note' => null,
        ];
    }
}
