<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\DailyJournal\Models\DailyReport;
use App\Domain\Projects\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyReport>
 */
class DailyReportFactory extends Factory
{
    protected $model = DailyReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'project_id' => Project::factory(),
            'report_date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'responsible_user_id' => User::factory(),
            'teams_present' => [],
            'photo_attachment_ids' => [],
            'status' => 'draft',
        ];
    }
}
