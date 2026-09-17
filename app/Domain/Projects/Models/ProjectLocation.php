<?php

namespace App\Domain\Projects\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Database\Factories\ProjectLocationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * docs/data-model.md "project_locations": optional WBS levels (corpus/zone
 * -> floor -> space), arbitrary depth via self-referencing
 * `parent_location_id`. All levels are optional (spec: "დონეები optional").
 */
class ProjectLocation extends Model
{
    /** @use HasFactory<ProjectLocationFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'project_id',
        'parent_location_id',
        'level_type',
        'name',
    ];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<ProjectLocation, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ProjectLocation::class, 'parent_location_id');
    }

    /**
     * @return HasMany<ProjectLocation, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(ProjectLocation::class, 'parent_location_id');
    }

    /**
     * @return HasMany<WorkPackage, $this>
     */
    public function workPackages(): HasMany
    {
        return $this->hasMany(WorkPackage::class);
    }

    protected static function newFactory(): ProjectLocationFactory
    {
        return ProjectLocationFactory::new();
    }
}
