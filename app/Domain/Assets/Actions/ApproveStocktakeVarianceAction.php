<?php

namespace App\Domain\Assets\Actions;

use App\Domain\Assets\Exceptions\InvalidCustodyStateException;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\StocktakeAdjustment;
use App\Domain\Assets\Models\StocktakeLine;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Stocktake, spec 9.6: the ONLY action that ever mutates a ledger balance
 * as a result of a stocktake variance. `StocktakeAdjustment` is an
 * append-only, separately-approved record — a `StocktakeLine.counted_
 * quantity` differing from `expected_quantity` is a mere observation until
 * this action runs. Locks the Asset row before mutating it, matching
 * FinalizeIssueAction's/DecideAssetIncidentAction's established locking
 * discipline in this same domain.
 *
 * `adjustment_type`:
 *  - `quantity_correction` — a quantity/consumable asset's counted amount
 *    becomes the new `quantity_on_hand` (a stocktake corrects the ledger to
 *    match physical reality, whichever direction the variance runs).
 *  - `marked_lost` — an individually-tracked asset expected but not found.
 *    Sets `Asset.condition = 'written_off'` — the same terminal state
 *    DecideAssetIncidentAction already uses for a decided loss/write-off
 *    (this schema has no separate "lost" condition value; reusing the
 *    existing one avoids inventing a parallel state DevicePolicy/
 *    FinalizeIssueAction's existing availability checks don't know about).
 *  - `confirmed_found` — reverses a previously-lost/written-off
 *    individually-tracked asset back to `good` once physically located.
 */
class ApproveStocktakeVarianceAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(StocktakeLine $line, string $adjustmentType, User $actor, ?string $notes = null): StocktakeAdjustment
    {
        if (! in_array($adjustmentType, ['quantity_correction', 'marked_lost', 'confirmed_found'], true)) {
            throw new InvalidArgumentException("Unknown adjustment type: {$adjustmentType}");
        }

        return DB::transaction(function () use ($line, $adjustmentType, $actor, $notes) {
            $line = StocktakeLine::whereKey($line->id)->lockForUpdate()->firstOrFail();

            if ($line->variance_approved_adjustment_id !== null) {
                throw new InvalidCustodyStateException('ეს ვარიაცია უკვე დამტკიცებულია.');
            }

            if ($line->counted_quantity === null) {
                throw new InvalidCustodyStateException('ჯერ არ არის ჩატარებული დათვლა ამ პოზიციაზე.');
            }

            $asset = Asset::whereKey($line->asset_id)->lockForUpdate()->firstOrFail();
            $quantityBefore = (float) $asset->quantity_on_hand;

            $quantityAfter = match ($adjustmentType) {
                'quantity_correction' => (float) $line->counted_quantity,
                'marked_lost' => 0.0,
                'confirmed_found' => 1.0,
            };

            if ($adjustmentType === 'quantity_correction') {
                $asset->update(['quantity_on_hand' => $quantityAfter]);
            } elseif ($adjustmentType === 'marked_lost') {
                $asset->update(['condition' => 'written_off']);
            } elseif ($adjustmentType === 'confirmed_found') {
                $asset->update(['condition' => 'good']);
            }

            $adjustment = StocktakeAdjustment::query()->create([
                'stocktake_line_id' => $line->id,
                'asset_id' => $asset->id,
                'adjustment_type' => $adjustmentType,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'approved_by_user_id' => $actor->id,
                'approved_at' => now(),
                'notes' => $notes,
            ]);

            $line->update([
                'variance_approved_adjustment_type' => $adjustment->getMorphClass(),
                'variance_approved_adjustment_id' => $adjustment->id,
            ]);

            $this->auditLogger->log(
                action: 'assets.stocktake.variance_approved',
                target: $adjustment,
                after: $adjustment->only(['adjustment_type', 'quantity_before', 'quantity_after']),
                reason: $notes,
                actor: $actor,
            );

            return $adjustment->fresh();
        });
    }
}
