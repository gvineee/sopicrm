<?php

namespace App\Domain\Auth\Models;

use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Models\User;
use Database\Factories\ProjectMembershipFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/data-model.md "project_memberships" — the concrete table behind spec
 * section 3's "პროექტის წევრობა და როლის უფლებები ერთად განსაზღვრავს
 * წვდომას" (project membership + role jointly decide access). Every
 * project-scoped Policy method must check this table (via
 * User::isActiveMemberOfProject()) in addition to the permission check —
 * see app/Policies/ProjectPolicy.php for the reference implementation every
 * later module's project-scoped Policy should follow.
 *
 * `removed_at` is a soft revoke, not a delete: history is kept
 * intentionally (spec: audit/history matters more than tidiness here).
 */
/**
 * @property Carbon $created_at
 * @property Carbon|null $removed_at
 */
class ProjectMembership extends Model
{
    /** @use HasFactory<ProjectMembershipFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'project_id',
        'user_id',
        'role_context',
        'added_by_user_id',
        'removed_at',
    ];

    protected function casts(): array
    {
        return [
            'removed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<ProjectMembership>  $query
     * @return Builder<ProjectMembership>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    /**
     * See App\Domain\Auth\Models\Organization::newFactory()'s docblock.
     *
     * @return ProjectMembershipFactory
     */
    protected static function newFactory()
    {
        return ProjectMembershipFactory::new();
    }
}
