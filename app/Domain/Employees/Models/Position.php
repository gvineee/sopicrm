<?php

namespace App\Domain\Employees\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Database\Factories\PositionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A predefined, organization-managed job position (spec section 5). Kept
 * deliberately simple (name + active flag) — it exists so `Employee::position_id`
 * can be filtered/reported on reliably, unlike the legacy free-text
 * `employees.position` column it supersedes for new/edited records.
 */
class Position extends Model
{
    /** @use HasFactory<PositionFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Employee, $this> */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    protected static function newFactory(): PositionFactory
    {
        return PositionFactory::new();
    }
}
