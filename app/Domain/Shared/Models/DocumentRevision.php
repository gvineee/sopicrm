<?php

namespace App\Domain\Shared\Models;

use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Models\User;
use Database\Factories\DocumentRevisionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * docs/data-model.md "document_revisions" (spec section 15). A `Task` binds
 * to one specific revision (`tasks.drawing_revision_id`) — uploading a newer
 * drawing never silently re-points existing tasks.
 */
class DocumentRevision extends Model
{
    /** @use HasFactory<DocumentRevisionFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'document_attachment_id',
        'project_id',
        'category',
        'revision_label',
        'author_user_id',
        'approved_at',
        'approved_by_user_id',
        'is_current',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'is_current' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Attachment, $this>
     */
    public function documentAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'document_attachment_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * @return HasMany<DocumentAnnotation, $this>
     */
    public function annotations(): HasMany
    {
        return $this->hasMany(DocumentAnnotation::class);
    }

    protected static function newFactory(): DocumentRevisionFactory
    {
        return DocumentRevisionFactory::new();
    }
}
