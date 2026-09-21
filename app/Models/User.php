<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domain\Auth\Models\Membership;
use App\Domain\Auth\Models\Organization;
use App\Domain\Auth\Models\ProjectMembership;
use App\Domain\Auth\Support\PermissionDenialCache;
use App\Domain\Companies\Models\Company;
use App\Domain\Companies\Models\CompanyMembership;
use App\Domain\Shared\Concerns\HasVersion;
use App\Domain\Shared\Services\CurrentOrganization;
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
 * @property string|null $current_company_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property bool $is_active
 * @property bool $is_platform_admin
 * @property bool $is_system_account
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
// `is_platform_admin` (ADMIN-01) and `is_system_account` (QUEUE-01) are
// deliberately excluded here — the former must only ever be set via
// App\Domain\Auth\Actions\GrantPlatformAdminAction, the latter only via
// App\Domain\Auth\Actions\GetOrCreateSystemActorAction, never through any
// ordinary profile/user-update form's mass assignment.
#[Fillable(['name', 'email', 'password', 'phone', 'is_active'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'mfa_secret_encrypted', 'personal_id_number_encrypted'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, HasVersion, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable {
        // ADMIN-02: HasRoles composes spatie's HasPermissions trait, which
        // defines hasPermissionTo() — aliased here so this class's own
        // override (below) can still reach the original spatie logic after
        // consulting the deny-override list first. `parent::` cannot be
        // used for a trait method (traits are not part of the real PHP
        // inheritance chain), hence this alias.
        HasRoles::hasPermissionTo as private spatieHasPermissionTo;
    }

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
            'is_platform_admin' => 'boolean',
            'is_system_account' => 'boolean',
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
     * @return BelongsTo<Company, $this>
     */
    public function currentCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'current_company_id');
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
     * @return HasMany<CompanyMembership, $this>
     */
    public function companyMemberships(): HasMany
    {
        return $this->hasMany(CompanyMembership::class);
    }

    public function isActiveMemberOfCompany(string $companyId): bool
    {
        return $this->companyMemberships()->where('company_id', $companyId)->exists();
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

    /**
     * ADMIN-02: overrides spatie's HasPermissions::hasPermissionTo() to
     * consult the deny-override list first. This is NOT redundant with
     * App\Providers\AppServiceProvider::boot()'s deny-check Gate::before —
     * spatie/laravel-permission registers its OWN Gate::before
     * (PermissionRegistrar::registerPermissions(), wired up the first time
     * Gate::class is ever resolved from the container) that calls
     * `$user->checkPermissionTo($ability)` and returns `true` immediately
     * the moment a role/direct grant provides the permission. That `true`
     * short-circuits Gate::raw() before ANY later-registered Gate::before
     * callback (including this app's own deny-check) ever runs, regardless
     * of registration order — the only way to make a deny actually win for
     * a role-granted permission is to intercept spatie's own resolution
     * path directly, here. `checkPermissionTo()` (also spatie's) simply
     * wraps this method in a try/catch, so overriding this one method
     * covers both.
     */
    public function hasPermissionTo(mixed $permission, ?string $guardName = null): bool
    {
        if (is_string($permission)) {
            $organizationId = CurrentOrganization::id();

            if ($organizationId !== null
                && in_array($permission, PermissionDenialCache::deniedPermissionNames($organizationId, $this->id), true)) {
                return false;
            }
        }

        return $this->spatieHasPermissionTo($permission, $guardName);
    }
}
