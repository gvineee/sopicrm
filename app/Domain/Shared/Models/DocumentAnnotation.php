<?php

namespace App\Domain\Shared\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Models\User;
use Database\Factories\DocumentAnnotationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/data-model.md "document_annotations" (spec section 15): markup stored
 * as a separate layer, never flattened into the original file.
 */
class DocumentAnnotation extends Model
{
    /** @use HasFactory<DocumentAnnotationFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'document_revision_id',
        'author_user_id',
        'annotation_data',
    ];

    protected function casts(): array
    {
        return [
            'annotation_data' => 'array',
        ];
    }

    /**
     * @return BelongsTo<DocumentRevision, $this>
     */
    public function documentRevision(): BelongsTo
    {
        return $this->belongsTo(DocumentRevision::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    protected static function newFactory(): DocumentAnnotationFactory
    {
        return DocumentAnnotationFactory::new();
    }
}
