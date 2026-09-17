<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Notifications\Models\OfflineSyncSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfflineSyncSubmission>
 */
class OfflineSyncSubmissionFactory extends Factory
{
    protected $model = OfflineSyncSubmission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'kind' => 'comment',
            'status' => 'applied',
            'payload' => ['body' => fake()->sentence()],
        ];
    }
}
