<?php

namespace App\Domain\Assets\Actions;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetLocation;
use App\Domain\Assets\Models\Stocktake;
use App\Domain\Assets\Models\StocktakeLine;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Stocktake (ASSETS-01 deferred remainder), spec 9.6: opens a physical
 * count session for one site/warehouse. The `expected_snapshot` and every
 * `StocktakeLine.expected_quantity` are captured NOW, from the asset's real
 * current state (`asset_locations.is_current` for scope membership,
 * `quantity_on_hand` for quantity/consumable assets, else a flat 1 per
 * individually-tracked asset) — this baseline is what every count in this
 * session is measured against, never recomputed later, so a later change
 * elsewhere in the system can't retroactively alter what this stocktake is
 * judging.
 */
class StartStocktakeAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(string $scopeType, string $scopeId, User $actor): Stocktake
    {
        return DB::transaction(function () use ($scopeType, $scopeId, $actor) {
            $assetIds = AssetLocation::query()
                ->where('locatable_type', $scopeType)
                ->where('locatable_id', $scopeId)
                ->where('is_current', true)
                ->pluck('asset_id');

            $assets = Asset::query()->whereIn('id', $assetIds)->get(['id', 'tracking_type', 'quantity_on_hand']);

            $expectedSnapshot = $assets->mapWithKeys(fn (Asset $asset) => [
                $asset->id => $this->expectedQuantityFor($asset),
            ])->all();

            $stocktake = Stocktake::query()->create([
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'session_started_at' => now(),
                'expected_snapshot' => $expectedSnapshot,
                'status' => 'in_progress',
                'performed_by_user_id' => $actor->id,
            ]);

            foreach ($assets as $asset) {
                StocktakeLine::query()->create([
                    'stocktake_id' => $stocktake->id,
                    'asset_id' => $asset->id,
                    'expected_quantity' => $this->expectedQuantityFor($asset),
                    'counted_quantity' => null,
                ]);
            }

            $this->auditLogger->log(
                action: 'assets.stocktake.started',
                target: $stocktake,
                after: ['scope_type' => $scopeType, 'scope_id' => $scopeId, 'asset_count' => $assets->count()],
                actor: $actor,
            );

            return $stocktake->fresh('lines.asset');
        });
    }

    private function expectedQuantityFor(Asset $asset): float
    {
        return in_array($asset->tracking_type, ['quantity', 'consumable'], true)
            ? (float) $asset->quantity_on_hand
            : 1.0;
    }
}
