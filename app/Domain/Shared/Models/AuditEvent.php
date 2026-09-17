<?php

namespace App\Domain\Shared\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Models\User;
use Database\Factories\AuditEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * docs/data-model.md "audit_events" — append-only. Rows are written only
 * through App\Domain\Shared\Services\AuditLogger, never constructed ad hoc,
 * so masking and requestId/actor capture happen in exactly one place. See
 * app/Policies/AuditEventPolicy.php for why no ordinary role can update or
 * delete a row here (hard constraint: "no ordinary admin able to edit audit
 * rows").
 *
 * `HasFactory`/`AuditEventFactory` added by the P0+P1 schema pass purely for
 * test-setup convenience — production code must still only ever create rows
 * via AuditLogger, never `AuditEvent::factory()->create()` outside tests.
 */
class AuditEvent extends Model
{
    /** @use HasFactory<AuditEventFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'actor_user_id',
        'actor_label',
        'action',
        'target_type',
        'target_id',
        'reason',
        'request_id',
        'ip_address',
        'before',
        'after',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * See App\Domain\Auth\Models\Organization::newFactory()'s docblock for
     * why this override is necessary for a model outside App\Models.
     *
     * @return AuditEventFactory
     */
    protected static function newFactory()
    {
        return AuditEventFactory::new();
    }
}
