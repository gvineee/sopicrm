<?php

namespace App\Domain\Shared\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Models\User;
use Database\Factories\ApprovalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * docs/data-model.md "approvals" — generic polymorphic approval record
 * reused by Timesheet, PayRun, TaskAcceptance, CredentialAssignment changes,
 * etc. `target_version` is the concrete implementation of spec section 19's
 * optimistic-concurrency rule: the approval Action re-checks the
 * approvable's current `version` equals `target_version` before committing,
 * else rejects as stale (409) — that check lives in the Domain layer for
 * whichever module creates the approval, not in this model.
 */
class Approval extends Model
{
    /** @use HasFactory<ApprovalFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'approvable_type',
        'approvable_id',
        'target_version',
        'approver_user_id',
        'decision',
        'reason',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    protected static function newFactory(): ApprovalFactory
    {
        return ApprovalFactory::new();
    }
}
