<?php

namespace App\Domain\Auth\Models;

use App\Models\User;
use Database\Factories\OrganizationFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/**
 * docs/data-model.md "organizations" — the tenant root. Has no
 * organization_id of its own and is excluded from RLS (it IS what RLS
 * policies compare against).
 *
 * Implements Authenticatable (trivially — no password/remember-token, it
 * never logs in interactively) purely so a Sanctum personal access token
 * can be issued directly to an Organization for machine/API identities
 * (spec sections 20/21: "services/device-connector" and future
 * server-to-server integrations authenticate as a distinct machine
 * identity, never a shared/generic API key, and its bound organization is
 * the token's own — see the `organization_id` column added to
 * personal_access_tokens). App\Console\Commands\IssueMachineToken is the
 * only supported way to create one of these tokens.
 */
class Organization extends Model implements Authenticatable
{
    /** @use HasFactory<OrganizationFactory> */
    use HasApiTokens, HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'legal_name',
        'default_currency',
        'default_timezone',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Overridden because this model lives under App\Domain\Auth\Models
     * rather than App\Models — Laravel's default factory name resolver only
     * strips an "App\Models\" (or bare "App\") prefix, so without this it
     * would guess "Database\Factories\Domain\Auth\Models\OrganizationFactory".
     *
     * @return OrganizationFactory
     */
    protected static function newFactory()
    {
        return OrganizationFactory::new();
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    // --- Illuminate\Contracts\Auth\Authenticatable ---------------------
    // Machine identity only: no password, no remember-me. Sanctum's token
    // guard never calls the password/remember-token methods for token
    // authentication, but the interface requires them to exist.

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->getKey();
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): ?string
    {
        return null;
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }
}
