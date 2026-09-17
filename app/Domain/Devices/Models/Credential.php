<?php

namespace App\Domain\Devices\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Database\Factories\CredentialFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * docs/data-model.md "credentials" (spec section 6). Bytes/length/leading
 * zeros are preserved as-read; decimal/hex/byte-order conversion is a
 * documented adapter concern outside this model. An unknown-card read never
 * auto-creates an Employee — it creates a triage record on the
 * `raw_access_events` row instead (`unmatched_credential_ref`).
 */
class Credential extends Model
{
    /** @use HasFactory<CredentialFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'card_type',
        'canonical_identifier',
        'raw_bytes',
        'bit_length',
        'leading_zeros_preserved',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'leading_zeros_preserved' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CredentialAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(CredentialAssignment::class);
    }

    protected static function newFactory(): CredentialFactory
    {
        return CredentialFactory::new();
    }
}
