<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Devices\Models\Site;
use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Database\Factories\ShiftTemplateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * docs/data-model.md "shift_templates" (spec section 7/8). Rounding/break
 * policy is documented, configurable JSON — never hardcoded in application
 * code.
 */
/**
 * @property array<int, string> $scheduled_days
 * @property array<string, mixed> $break_policy
 * @property array<string, mixed> $rounding_policy
 */
class ShiftTemplate extends Model
{
    /** @use HasFactory<ShiftTemplateFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'site_id',
        'name',
        'starts_at_local',
        'ends_at_local',
        'crosses_midnight',
        'scheduled_days',
        'break_policy',
        'allowed_late_minutes',
        'rounding_policy',
        'requires_approval_by_role',
    ];

    protected function casts(): array
    {
        return [
            'crosses_midnight' => 'boolean',
            'scheduled_days' => 'array',
            'break_policy' => 'array',
            'rounding_policy' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    protected static function newFactory(): ShiftTemplateFactory
    {
        return ShiftTemplateFactory::new();
    }
}
