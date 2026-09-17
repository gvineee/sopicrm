<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\DailyJournal\Models\DailyReport;
use App\Domain\DailyJournal\Models\DailyReportRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyReportRevision>
 */
class DailyReportRevisionFactory extends Factory
{
    protected $model = DailyReportRevision::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'daily_report_id' => DailyReport::factory(),
            'snapshot' => [],
            'revised_by_user_id' => User::factory(),
            'revised_at' => now(),
        ];
    }
}
