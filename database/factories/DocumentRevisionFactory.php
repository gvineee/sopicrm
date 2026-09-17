<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Shared\Models\Attachment;
use App\Domain\Shared\Models\DocumentRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentRevision>
 */
class DocumentRevisionFactory extends Factory
{
    protected $model = DocumentRevision::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'document_attachment_id' => Attachment::factory(),
            'revision_label' => 'Rev '.strtoupper(fake()->randomLetter()),
            'author_user_id' => User::factory(),
            'is_current' => true,
        ];
    }
}
