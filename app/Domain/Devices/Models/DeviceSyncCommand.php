<?php

namespace App\Domain\Devices\Models;

use App\Domain\Shared\Concerns\BelongsToOrganization;
use App\Domain\Shared\Concerns\HasVersion;
use Carbon\CarbonInterface;
use Database\Factories\DeviceSyncCommandFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/data-model.md "device_sync_commands" (spec section 6). The UI must
 * show DESIRED state (this table) separately from ACKNOWLEDGED state (the
 * last successfully applied command per device+entity) — an offline
 * device's pending revocation must never render as completed. Command
 * versioning is monotonic per (device_id, target_entity): a stale in-flight
 * retry must never overwrite a newer command's effect — that comparison is
 * a Domain/connector-ingestion concern, not enforced by a DB constraint
 * here (the natural key isn't unique — multiple commands legitimately queue
 * for the same target over time).
 */
/**
 * @property array<string, mixed> $payload
 * @property CarbonInterface|null $acknowledged_at
 */
class DeviceSyncCommand extends Model
{
    /** @use HasFactory<DeviceSyncCommandFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, HasVersion;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'device_id',
        'command_type',
        'payload',
        'idempotency_key',
        'target_entity_type',
        'target_entity_id',
        'command_version',
        'status',
        'attempts',
        'last_error',
        'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'acknowledged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    protected static function newFactory(): DeviceSyncCommandFactory
    {
        return DeviceSyncCommandFactory::new();
    }
}
