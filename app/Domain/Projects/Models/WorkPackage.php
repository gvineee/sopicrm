<?php

namespace App\Domain\Projects\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Database\Factories\WorkPackageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/data-model.md "work_packages".
 */
class WorkPackage extends Model
{
    /** @use HasFactory<WorkPackageFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'project_id',
        'project_location_id',
        'name',
        'description',
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
    public function projectLocation(): BelongsTo
    {
        return $this->belongsTo(ProjectLocation::class);
    }

    protected static function newFactory(): WorkPackageFactory
    {
        return WorkPackageFactory::new();
    }
}
