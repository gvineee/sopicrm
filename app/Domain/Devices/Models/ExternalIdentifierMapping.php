<?php

namespace App\Domain\Devices\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\ExternalIdentifierMappingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BIO-02 triage row for an external (BioStar) card/user/device identifier
 * this CRM does not yet recognize — see the creating migration's docblock
 * for why `source_instance_key` is never null. Only `external_type ===
 * 'card'` has a confirm action wired today
 * (App\Domain\Devices\Actions\ConfirmExternalIdentifierMappingAction); the
 * generic `target_type`/`target_id` pair exists so 'device'/'user' rows can
 * still be recorded and are forward-compatible with a real confirm action
 * later, without a further schema change.
 *
 * @property CarbonInterface $first_seen_at
 * @property CarbonInterface|null $confirmed_at
 */
class ExternalIdentifierMapping extends Model
{
    /** @use HasFactory<ExternalIdentifierMappingFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'source_system',
        'source_instance_key',
        'external_type',
        'external_identifier',
        'status',
        'target_type',
        'target_id',
        'first_seen_at',
        'confirmed_by_user_id',
        'confirmed_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<ExternalIdentifierMapping>  $query
     * @return Builder<ExternalIdentifierMapping>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function targetCredential(): ?Credential
    {
        if ($this->target_type !== Credential::class || $this->target_id === null) {
            return null;
        }

        return Credential::query()->find($this->target_id);
    }

    protected static function newFactory(): ExternalIdentifierMappingFactory
    {
        return ExternalIdentifierMappingFactory::new();
    }
}
