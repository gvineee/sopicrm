<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domain\Auth\Models\Membership;
use App\Domain\Auth\Models\Organization;
use App\Domain\Auth\Models\ProjectMembership;
use App\Domain\Shared\Concerns\HasVersion;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Access domain — docs/data-model.md "users". Distinct from a future
 * `Employee` (spec section 5: "Employee და login account ცალკე ცნებებია"):
 * a User is a login account, an Employee may or may not have one.
 *
 * `organization_id` (home org) is NOT-NULL + FK-constrained, but this model
 * deliberately does NOT use `App\Domain\Shared\Concerns\BelongsToOrganization`
 * (unlike every other tenant-scoped model) and `users` is excluded from
 * Postgres RLS too (see that migration's docblock) — both for the same
 * reason: authentication itself, and several of Laravel Fortify's own
 * internal helpers (the two-factor challenge's `TwoFactorLoginRequest::
 * challengedUser()`, password-reset broker lookups, signed
 * email-verification URLs) call `User::find()`/query the model directly,
 * with no tenant context established yet and no way to route through
 * a custom auth provider — see App\Domain\Auth\Support\ActiveUserProvider,
 * whose own purpose is unrelated (gating on `is_active`), not this. A global
 * scope that fails closed without tenant context (as ours does) would
 * silently break every one of those flows. Any FEATURE that lists/manages
 * users across an organization (a future Users-management screen) must
 * filter explicitly (`User::where('organization_id', $id)`) rather than
 * relying on an ambient scope — the deliberate cost of this decision is
 * that such filtering is opt-in, not automatic, for this one model only.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $current_organization_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'phone', 'is_active'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'mfa_secret_encrypted', 'personal_id_number_encrypted'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, HasVersion, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'personal_id_number_encrypted' => 'encrypted',
            'mfa_secret_encrypted' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return HasMany<ProjectMembership, $this>
     */
    public function projectMemberships(): HasMany
    {
        return $this->hasMany(ProjectMembership::class);
    }

    /**
     * Spec section 3: "პროექტის წევრობა და როლის უფლებები ერთად
     * განსაზღვრავს წვდომას" — a permission alone is never sufficient for a
     * project-scoped resource. Every project-scoped Policy calls this
     * alongside a permission check (see app/Policies/ProjectPolicy.php).
     */
    public function isActiveMemberOfProject(string $projectId): bool
    {
        return $this->projectMemberships()
            ->where('project_id', $projectId)
            ->whereNull('removed_at')
            ->exists();
    }
}
