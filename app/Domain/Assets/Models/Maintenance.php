<?php

namespace App\Domain\Assets\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Database\Factories\MaintenanceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/data-model.md "maintenance".
 */
/**
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $next_service_due_at
 */
class Maintenance extends Model
{
    /** @use HasFactory<MaintenanceFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    // The real migration (2026_09_16_090190_create_assets_domain_tables.php)
    // creates a SINGULAR `maintenance` table — "maintenance" is a mass noun
    // Eloquent's default pluralizer otherwise guesses as `maintenances`,
    // which doesn't exist. This was a real, previously-latent bug: the
    // table had zero rows/queries against it anywhere until this pass
    // wired the first real Actions that actually write to it.
    protected $table = 'maintenance';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'asset_id',
        'vendor',
        'scheduled_at',
        'completed_at',
        'actual_cost',
        'next_service_due_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
            'actual_cost' => 'decimal:2',
            'next_service_due_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    protected static function newFactory(): MaintenanceFactory
    {
        return MaintenanceFactory::new();
    }
}
