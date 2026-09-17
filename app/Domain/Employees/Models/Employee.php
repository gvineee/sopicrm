<?php

namespace App\Domain\Employees\Models;

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
 */
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'internal_code',
        'first_name',
        'last_name',
        'phone',
        'personal_id_number_encrypted',
        'photo_attachment_id',
        'position',
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
