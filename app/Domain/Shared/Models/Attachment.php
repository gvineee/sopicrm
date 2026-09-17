<?php

namespace App\Domain\Shared\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Models\User;
use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * docs/data-model.md "attachments" — the single upload-lifecycle table
 * (initiated -> uploaded -> scanning -> available/quarantined/failed, spec
 * section 20) every other domain's photo/PDF fields reference.
 *
 * `owner` is a plain polymorphic relation with NO DB-level FK (an
 * attachment's owner can be any of a dozen tables) — spec section 19
 * explicit rule: existence AND authorization of the owner must be
 * re-checked server-side on every access, which is a Domain/Policy concern
 * for whichever module resolves an attachment, not something this model or
 * a DB constraint can enforce by itself.
 */
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'owner_type',
        'owner_id',
        'disk',
        'storage_path',
        'original_filename',
        'mime_type',
        'byte_size',
        'checksum',
        'status',
        'preview_path',
        'uploaded_by_user_id',
        'caption',
        'classification',
        'taken_at_client_claimed',
        'gps_latitude',
        'gps_longitude',
        'gps_consent_given',
    ];

    protected function casts(): array
    {
        return [
            'taken_at_client_claimed' => 'datetime',
            'gps_latitude' => 'decimal:7',
            'gps_longitude' => 'decimal:7',
            'gps_consent_given' => 'boolean',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    protected static function newFactory(): AttachmentFactory
    {
        return AttachmentFactory::new();
    }
}
