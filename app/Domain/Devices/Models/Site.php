<?php

namespace App\Domain\Devices\Models;

use App\Domain\Companies\Models\Company;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * docs/data-model.md "sites" (Devices domain, spec section 6).
 *
 * `company_id` (TENANT-01, nullable): NULL means "unmapped" — visible to
 * anyone who could already see it via organization_id alone, never hidden
 * just because it hasn't been assigned yet. See app/Domain/Devices/Support/CompanyScope.php
 * for the shared visibility rule this participates in.
 */
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'company_id',
        'name',
        'address',
        'timezone',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    protected static function newFactory(): SiteFactory
    {
        return SiteFactory::new();
    }
}
