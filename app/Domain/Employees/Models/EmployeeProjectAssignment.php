<?php

namespace App\Domain\Employees\Models;

use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Database\Factories\EmployeeProjectAssignmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/data-model.md "employee_project_assignments". Unlike rate_histories,
 * overlapping assignments are allowed by design (an employee may be
 * assigned to more than one project's date range at once); attendance still
 * resolves the actual worked project per session, not per assignment.
 */
/**
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 */
class EmployeeProjectAssignment extends Model
{
    /** @use HasFactory<EmployeeProjectAssignmentFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'employee_id',
        'project_id',
        'starts_on',
        'ends_on',
        'assignment_type',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
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

    protected static function newFactory(): EmployeeProjectAssignmentFactory
    {
        return EmployeeProjectAssignmentFactory::new();
    }
}
