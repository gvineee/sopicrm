<?php

namespace App\Domain\Employees\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * docs/data-model.md "teams" (Employees domain, spec section 5).
 */
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'name',
        'foreman_employee_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function foreman(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'foreman_employee_id');
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(Employee::class, 'team_id');
    }

    /**
     * @return HasMany<TeamMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TeamMembership::class);
    }

    protected static function newFactory(): TeamFactory
    {
        return TeamFactory::new();
    }
}
