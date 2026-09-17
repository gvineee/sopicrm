<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Tasks\Models\ChecklistItem;
use App\Domain\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChecklistItem>
 */
class ChecklistItemFactory extends Factory
{
    protected $model = ChecklistItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'task_id' => Task::factory(),
            'label' => fake()->sentence(3),
            'is_required' => true,
            'is_checked' => false,
        ];
    }
}
