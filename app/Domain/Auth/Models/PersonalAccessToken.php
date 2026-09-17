<?php

namespace App\Domain\Auth\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Extends Sanctum's own model only to add `organization_id` (the machine
 * token's bound organization — see 2026_09_16_090105_..., App\Console\Commands\IssueMachineToken,
 * and App\Http\Middleware\SetCurrentOrganization). Registered via
 * `Sanctum::usePersonalAccessTokenModel()` in
 * App\Providers\Auth\AuthModuleServiceProvider.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'organization_id',
    ];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
