<?php

namespace App\Domain\Contractors\Models;

use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Mirrors App\Domain\Employees\Models\EmployeeProjectAssignment.
 *
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 */
class ContractorProjectAssignment extends Model
{
    use BelongsToOrganization, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'contractor_id',
        'project_id',
        'contract_id',
        'starts_on',
        'ends_on',
        'scope_description',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /** @return BelongsTo<Contractor, $this> */
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ContractorContract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(ContractorContract::class, 'contract_id');
    }
}
