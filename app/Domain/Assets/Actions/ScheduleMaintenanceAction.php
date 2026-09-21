<?php

namespace App\Domain\Assets\Actions;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\Maintenance;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * REQ-AST-07 remainder: a standalone "schedule service for this asset"
 * action — reachable regardless of how the asset entered `under_repair`
 * (a decided incident, or simply routine preventive maintenance nobody
 * reported as damage). Creates the record only; it does not itself change
 * `Asset.condition` — an asset already `under_repair` (via
 * `DecideAssetIncidentAction`) stays that way until the work actually
 * completes (`CompleteMaintenanceAction`), and a routine/preventive
 * maintenance booking for an asset still in `good` condition should not be
 * force-flipped to `under_repair` just because a future service date exists.
 */
class ScheduleMaintenanceAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{vendor?: ?string, scheduled_at?: ?string, notes?: ?string}  $data
     */
    public function execute(Asset $asset, array $data, User $actor): Maintenance
    {
        return DB::transaction(function () use ($asset, $data, $actor) {
            $maintenance = Maintenance::query()->create([
                'organization_id' => $asset->organization_id,
                'asset_id' => $asset->id,
                'vendor' => $data['vendor'] ?? null,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->auditLogger->log(
                action: 'assets.maintenance.scheduled',
                target: $maintenance,
                after: $maintenance->only(['vendor', 'scheduled_at']),
                actor: $actor,
            );

            return $maintenance->fresh();
        });
    }
}
