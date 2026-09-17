<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Tasks\Models\Comment;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    protected $model = Comment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'commentable_type' => Task::class,
            'commentable_id' => Task::factory(),
            'author_user_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }
}
