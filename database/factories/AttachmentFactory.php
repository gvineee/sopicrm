<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Shared\Models\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'owner_type' => 'test_owner',
            'owner_id' => (string) Str::uuid(),
            'disk' => 'private',
            'storage_path' => 'attachments/'.Str::uuid().'.jpg',
            'original_filename' => fake()->word().'.jpg',
            'mime_type' => 'image/jpeg',
            'byte_size' => fake()->numberBetween(1024, 5_000_000),
            'status' => 'available',
            'uploaded_by_user_id' => User::factory(),
        ];
    }
}
