<?php

namespace App\Domain\Employees\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Database\Factories\EmploymentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/data-model.md "employments". Ending an employment (a) revokes the
 * linked User login, (b) schedules DeviceSyncCommand revocations for active
 * CredentialAssignments, (c) surfaces (never auto-settles) unreturned
 * CustodyTransactions — spec explicit; those side effects are Domain Action
 * concerns for the Employees module agent, not modeled as columns here.
 */
/**
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 */
class Employment extends Model
{
    /** @use HasFactory<EmploymentFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'employee_id',
        'started_at',
        'ended_at',
        'end_reason',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'date',
            'ended_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    protected static function newFactory(): EmploymentFactory
    {
        return EmploymentFactory::new();
    }
}
