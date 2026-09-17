<?php

namespace App\Domain\Employees\Models;

use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use App\Models\User;
use Database\Factories\RateHistoryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/data-model.md "rate_histories" (spec section 5 hard rule): a null
 * `project_id` is the employee's base rate; non-null is a project override,
 * which takes priority over the base rate for that date. Non-overlapping
 * effective periods per (employee, project-or-base, rate_type) are enforced
 * at the DB level via a Postgres exclusion constraint (see the Employees
 * domain migration) — this model does not re-implement that check, only
 * exposes the data.
 *
 * Rate *resolution* (which row applies for a given employee + work date +
 * project, and blocking accrual when none applies) is a Domain service
 * concern for the Payroll/Attendance module agents, not this model.
 */
/**
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 */
class RateHistory extends Model
{
    /** @use HasFactory<RateHistoryFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'employee_id',
        'project_id',
        'rate_type',
        'amount',
        'currency',
        'effective_from',
        'effective_to',
        'change_reason',
        'approved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    protected static function newFactory(): RateHistoryFactory
    {
        return RateHistoryFactory::new();
    }
}
