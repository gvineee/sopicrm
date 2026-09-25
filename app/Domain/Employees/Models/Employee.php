<?php

namespace App\Domain\Employees\Models;

use App\Domain\Companies\Models\Company;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Domain\Shared\Models\Attachment;
use App\Models\User;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * docs/data-model.md "employees" (spec section 5). Distinct from `User`: an
 * Employee is a person on the payroll/roster; a User is a login account. An
 * Employee may have no `user_id` at all.
 *
 * `personal_id_number_encrypted` is restricted-visibility data (spec section
 * 5) — this pass casts it as plain `encrypted` (Laravel's built-in
 * attribute-encryption cast, using `APP_KEY`) so it is never stored in
 * plaintext; the *display*-time permission gate (masking it in low-privilege
 * views) is a Policy/Resource-layer concern for the Employees module agent,
 * not something the Eloquent cast itself can express.
 *
 * `status` is declared here because static analysis otherwise infers the set of
 * allowed values from the original `enum` in the create-table migration, and so
 * does not know about `pending_verification` — which a later additive migration
 * added by widening the PostgreSQL CHECK constraint rather than by rewriting
 * the column. Left undeclared, every `$employee->status !== self::STATUS_PENDING_VERIFICATION`
 * guard reads as trivially true and the code enforcing it reads as dead.
 *
 * @property 'active'|'inactive'|'terminated'|'pending_verification' $status
 * @property string|null $biostar_user_id
 */
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * A person created from a BioStar sync exists and is matched to their
     * swipes, but is explicitly not yet a working member of staff: no
     * department, no confirmed permissions. Distinct from `inactive`, which
     * means a person the organization knows and has stood down.
     */
    public const STATUS_PENDING_VERIFICATION = 'pending_verification';

    public const STATUS_ACTIVE = 'active';

    protected $fillable = [
        'organization_id',
        'company_id',
        'internal_code',
        // BioStar's own id for this person. A card can be reissued; this does
        // not change, so it is the durable anchor between the two systems.
        'biostar_user_id',
        'first_name',
        'last_name',
        'phone',
        'personal_id_number_encrypted',
        'photo_attachment_id',
        'position',
        'position_id',
        'profession_skills',
        'team_id',
        'supervisor_employee_id',
        'status',
        'emergency_contact_name',
        'emergency_contact_phone',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'profession_skills' => 'array',
            'personal_id_number_encrypted' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Named `jobPosition`, not `position` — `position` is already a real
     * database column (the legacy free-text field this relation
     * supersedes), and Eloquent always resolves an existing attribute over
     * a same-named relation, which would make a `position()` relation
     * silently unreachable via `$employee->position`.
     *
     * @return BelongsTo<Position, $this>
     */
    public function jobPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_employee_id');
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'supervisor_employee_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * TENANT-01 (nullable): NULL means unmapped — see
     * App\Domain\Devices\Support\CompanyScope for the shared visibility
     * rule this participates in.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Attachment, $this>
     */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'photo_attachment_id');
    }

    /**
     * @return HasMany<Employment, $this>
     */
    public function employments(): HasMany
    {
        return $this->hasMany(Employment::class);
    }

    /**
     * @return HasMany<RateHistory, $this>
     */
    public function rateHistories(): HasMany
    {
        return $this->hasMany(RateHistory::class);
    }

    /**
     * @return HasMany<EmployeeProjectAssignment, $this>
     */
    public function projectAssignments(): HasMany
    {
        return $this->hasMany(EmployeeProjectAssignment::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'owner')
            ->where('classification', 'employee_document');
    }

    protected static function newFactory(): EmployeeFactory
    {
        return EmployeeFactory::new();
    }
}
