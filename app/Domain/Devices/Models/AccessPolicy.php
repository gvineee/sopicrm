<?php

namespace App\Domain\Devices\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Database\Factories\AccessPolicyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * docs/data-model.md "access_policies".
 */
class AccessPolicy extends Model
{
    /** @use HasFactory<AccessPolicyFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'name',
        'site_ids',
        'schedule_definition',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'site_ids' => 'array',
            'schedule_definition' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<CredentialAssignment, $this>
     */
    public function credentialAssignments(): BelongsToMany
    {
        return $this->belongsToMany(
            CredentialAssignment::class,
            'access_policy_assignments'
        )->withTimestamps();
    }

    protected static function newFactory(): AccessPolicyFactory
    {
        return AccessPolicyFactory::new();
    }
}
