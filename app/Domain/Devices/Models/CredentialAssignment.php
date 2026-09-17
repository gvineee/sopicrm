<?php

namespace App\Domain\Devices\Models;

use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Carbon\CarbonInterface;
use Database\Factories\CredentialAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/data-model.md "credential_assignments" (spec section 6 hard rule:
 * "ბარათის აქტიური მინიჭება უნიკალურია"). At most one ACTIVE assignment per
 * credential at a time, enforced by a partial unique index (see the Devices
 * domain migration) — re-issuing a card creates a NEW row (the old one
 * transitions to `superseded`), never mutates history. Resolving which
 * assignment was active at a past instant is always a time-range query
 * against this table's history, never a mutable "current owner" pointer.
 */
/**
 * @property list<string>|null $site_scope
 * @property CarbonInterface $valid_from
 * @property CarbonInterface|null $valid_to
 */
class CredentialAssignment extends Model
{
    /** @use HasFactory<CredentialAssignmentFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'credential_id',
        'employee_id',
        'valid_from',
        'valid_to',
        'site_scope',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'site_scope' => 'array',
        ];
    }

    /**
     * @param  Builder<CredentialAssignment>  $query
     * @return Builder<CredentialAssignment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Resolve historical ownership by validity window, never by the
     * credential's current assignment/status pointer.
     *
     * @param  Builder<CredentialAssignment>  $query
     * @return Builder<CredentialAssignment>
     */
    public function scopeActiveAt(Builder $query, CarbonInterface $at): Builder
    {
        return $query
            ->where('valid_from', '<=', $at)
            ->where(function (Builder $window) use ($at): void {
                $window->whereNull('valid_to')->orWhere('valid_to', '>=', $at);
            });
    }

    /**
     * @return BelongsTo<Credential, $this>
     */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    protected static function newFactory(): CredentialAssignmentFactory
    {
        return CredentialAssignmentFactory::new();
    }
}
