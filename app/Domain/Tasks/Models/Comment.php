<?php

namespace App\Domain\Tasks\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Models\User;
use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * docs/data-model.md "comments" — polymorphic, primarily on `tasks`,
 * extendable to other commentable models later without a schema change.
 */
/**
 * @property list<array{body: string, edited_at: string}>|null $edit_history
 * @property Carbon|null $edited_at
 * @property Carbon $created_at
 */
class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'commentable_type',
        'commentable_id',
        'author_user_id',
        'body',
        'mentions',
        'parent_comment_id',
        'edited_at',
        'edit_history',
    ];

    protected function casts(): array
    {
        return [
            'mentions' => 'array',
            'edited_at' => 'datetime',
            'edit_history' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * @return BelongsTo<Comment, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'parent_comment_id');
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_comment_id');
    }

    protected static function newFactory(): CommentFactory
    {
        return CommentFactory::new();
    }
}
