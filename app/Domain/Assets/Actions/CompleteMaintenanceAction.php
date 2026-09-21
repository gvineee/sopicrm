<?php

namespace App\Domain\Assets\Actions;

use App\Domain\Assets\Exceptions\InvalidCustodyStateException;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\Maintenance;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * REQ-AST-07 remainder: completing service is the ONE place a
 * `Maintenance` record ever restores `Asset.condition` — matching
 * `ApproveStocktakeVarianceAction`'s `confirmed_found` case and
 * `ReturnCustodyAction`'s own condition-restoration convention in this same
 * domain (lock the asset before mutating it). Only restores the asset to
 * `good` if it's currently `under_repair` — completing a routine/preventive
 * service booking for an asset that was never flagged `under_repair` in the
 * first place must not silently overwrite some other real condition value
 * (e.g. it must never downgrade a `written_off` asset back to `good` just
 * because an unrelated maintenance row happened to reference it).
 */
class CompleteMaintenanceAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{actual_cost?: ?string, next_service_due_at?: ?string, notes?: ?string}  $data
     */
    public function execute(Maintenance $maintenance, array $data, User $actor): Maintenance
    {
        return DB::transaction(function () use ($maintenance, $data, $actor) {
            $maintenance = Maintenance::whereKey($maintenance->id)->lockForUpdate()->firstOrFail();

            if ($maintenance->completed_at !== null) {
                throw InvalidCustodyStateException::forTransition('completed', 'completed');
            }

            $before = $maintenance->only(['completed_at']);

            $maintenance->fill([
                'completed_at' => now(),
                'actual_cost' => $data['actual_cost'] ?? null,
                'next_service_due_at' => $data['next_service_due_at'] ?? null,
                'notes' => $data['notes'] ?? $maintenance->notes,
            ])->save();

            $asset = Asset::whereKey($maintenance->asset_id)->lockForUpdate()->first();

            if ($asset !== null && $asset->condition === 'under_repair') {
                $asset->update(['condition' => 'good']);
            }

            $this->auditLogger->log(
                action: 'assets.maintenance.completed',
                target: $maintenance,
                before: $before,
                after: $maintenance->only(['completed_at', 'actual_cost']),
                actor: $actor,
            );

            return $maintenance->fresh();
        });
    }
}
