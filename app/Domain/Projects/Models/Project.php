<?php

namespace App\Domain\Projects\Models;

use App\Domain\Auth\Models\ProjectMembership;
use App\Domain\Companies\Models\Company;
use App\Domain\Devices\Models\Site;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Domain\Shared\Models\Attachment;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Extended by the P0+P1 schema pass (docs/data-model.md "projects") from the
 * Auth/RBAC/Tenancy pass's original minimal placeholder — see the docblock
 * on database/migrations/2026_09_16_090060_create_projects_table.php for why
 * it started minimal. `client_id`/`manager_user_id`/dates/status/budget were
 * added by database/migrations/2026_09_16_090210_extend_projects_and_create_clients_table.php.
 * `site_id` was added by the Attendance module's additive migration
 * (2026_09_17_090000_add_site_id_to_projects_table.php, see
 * docs/decisions.md): a project's primary physical site, used ONLY to
 * resolve which project an `AttendanceSession` at a given site/date belongs
 * to (spec section 7 REQ-ATT-08) — never guessed when null.
 * The Projects module agent still owns this model going forward for
 * anything beyond the fields the data model already specifies (WBS UI,
 * budget revisions, etc.), per docs/architecture.md §7.
 */
/**
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'company_id',
        'name',
        'code',
        'client_id',
        'site_id',
        'manager_user_id',
        'address',
        'starts_on',
        'ends_on',
        'status',
        'budget_baseline',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'budget_baseline' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<ProjectMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMembership::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Spec section 10 optional WBS: project -> corpus/zone -> floor ->
     * space. Only the top-level (no parent) rows here — see
     * ProjectLocation::children() for descending the tree.
     *
     * @return HasMany<ProjectLocation, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(ProjectLocation::class);
    }

    /**
     * @return HasMany<WorkPackage, $this>
     */
    public function workPackages(): HasMany
    {
        return $this->hasMany(WorkPackage::class);
    }

    /**
     * Exists so the nested task routes can use scoped route-model binding
     * (`->scopeBindings()`): `/projects/{project}/tasks/{task}` then resolves
     * the task THROUGH this relation, and a task id belonging to another
     * project 404s at the binding instead of reaching a controller that
     * might forget to re-check the parent link (TM-07/SEC-01).
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Project-level documents (spec section 10: "დოკუmentები"). Uses the
     * generic cross-cutting `attachments` polymorphic owner relation
     * directly rather than `document_revisions` (that table's
     * revision-tracking shape is for drawings bound to a specific Task —
     * out of this module's scope, see docs/decisions.md).
     *
     * @return MorphMany<Attachment, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'owner');
    }

    /**
     * See Organization::newFactory()'s docblock for why this override
     * exists.
     *
     * @return ProjectFactory
     */
    protected static function newFactory()
    {
        return ProjectFactory::new();
    }
}
