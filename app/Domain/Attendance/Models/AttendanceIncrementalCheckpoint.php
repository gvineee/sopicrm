<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use Database\Factories\AttendanceIncrementalCheckpointFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * QUEUE-01: the "last processed" resume point `attendance:process-incremental`
 * uses so it only re-runs reconstruction for an employee with genuinely NEW
 * raw events since last time — never every employee on every tick.
 */
/** @property Carbon $last_processed_at */
class AttendanceIncrementalCheckpoint extends Model
{
    /** @use HasFactory<AttendanceIncrementalCheckpointFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'employee_id',
        'last_processed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    protected static function newFactory(): AttendanceIncrementalCheckpointFactory
    {
        return AttendanceIncrementalCheckpointFactory::new();
    }
}
