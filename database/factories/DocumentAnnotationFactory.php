<?php

namespace Database\Factories;

use App\Domain\Auth\Models\Organization;
use App\Domain\Shared\Models\DocumentAnnotation;
use App\Domain\Shared\Models\DocumentRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentAnnotation>
 */
class DocumentAnnotationFactory extends Factory
{
    protected $model = DocumentAnnotation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'document_revision_id' => DocumentRevision::factory(),
            'author_user_id' => User::factory(),
            'annotation_data' => ['shapes' => []],
        ];
    }
}
