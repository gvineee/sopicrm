<?php

namespace App\Domain\Employees\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\EmployeeInviteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR-issued, time-limited, single-use invite link that lets an Employee
 * (who may have no login account at all — spec section 5: "Employee და
 * login account ცალკე ცნებებია") create one. Only `token_hash` is ever
 * persisted; the raw token exists solely in the one-time URL. See the
 * migration's own docblock (2026_09_16_120000_create_employee_invites_table)
 * and App\Domain\Employees\Actions\IssueEmployeeInviteAction /
 * AcceptEmployeeInviteAction for the actual issue/accept logic — this model
 * is intentionally a plain data holder with no business logic of its own.
 */
/**
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $accepted_at
 * @property CarbonInterface|null $revoked_at
 */
class EmployeeInvite extends Model
{
    /** @use HasFactory<EmployeeInviteFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'employee_id',
        'token_hash',
        'expires_at',
        'status',
        'created_by_user_id',
        'accepted_at',
        'accepted_user_id',
        'revoked_at',
        'revoked_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }

    protected static function newFactory(): EmployeeInviteFactory
    {
        return EmployeeInviteFactory::new();
    }
}
