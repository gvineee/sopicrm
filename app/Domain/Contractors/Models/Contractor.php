<?php

namespace App\Domain\Contractors\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An external company or team performing work on our projects/tasks under a
 * contract — distinct from `App\Domain\Companies\Models\Company` (our own
 * internal tenant sub-companies) and from `Employee` (always internal). A
 * Contractor is never a task's accountable owner, only an additional
 * performer — see `App\Domain\Tasks\Models\TaskAssignee::contractor()`.
 */
class Contractor extends Model
{
    use BelongsToOrganization, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'name',
        'legal_name',
        'tax_id',
        'contact_person',
        'phone',
        'email',
        'default_currency',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<ContractorContract, $this> */
    public function contracts(): HasMany
    {
        return $this->hasMany(ContractorContract::class);
    }

    /** @return HasMany<ContractorProjectAssignment, $this> */
    public function projectAssignments(): HasMany
    {
        return $this->hasMany(ContractorProjectAssignment::class);
    }

    /** @return HasMany<ContractorAct, $this> */
    public function acts(): HasMany
    {
        return $this->hasMany(ContractorAct::class);
    }

    /** @return HasMany<ContractorPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(ContractorPayment::class);
    }
}
