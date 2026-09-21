<?php

namespace App\Domain\Assets\Actions;

use App\Domain\Assets\Exceptions\InvalidCustodyStateException;
use App\Domain\Assets\Models\Stocktake;
use App\Domain\Assets\Models\StocktakeLine;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Stocktake, spec 9.6 hard rule: "სკანირება პირდაპირ არ ცვლის საბუღალტრო
 * ნაშთს" — recording a physical count is PURELY an observation. This
 * action writes `counted_quantity` and nothing else: it never touches
 * `assets.quantity_on_hand`, `asset_active_custody`, or an asset's
 * `condition`. Only ApproveStocktakeVarianceAction, acting on a
 * counted-but-unresolved line, is allowed to create the real ledger-
 * mutating record.
 *
 * A recount NEVER overwrites the original line — it creates a NEW
 * StocktakeLine referencing the original via `recount_of_line_id`
 * (immutable count history, matching this session's established custody-
 * history principle: a correction is a new row, not an edit).
 */
class ScanStocktakeLineAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(StocktakeLine $line, float $countedQuantity, User $actor, bool $isRecount = false): StocktakeLine
    {
        return DB::transaction(function () use ($line, $countedQuantity, $actor, $isRecount) {
            $stocktake = Stocktake::whereKey($line->stocktake_id)->lockForUpdate()->firstOrFail();

            if ($stocktake->status !== 'in_progress') {
                throw InvalidCustodyStateException::forTransition($stocktake->status, 'counted');
            }

            if (! $isRecount) {
                if ($line->counted_quantity !== null) {
                    throw new InvalidCustodyStateException(
                        'ეს პოზიცია უკვე დათვლილია — თუ გსურთ ხელახლა დათვლა, გამოიყენეთ "ხელახლა დათვლა".'
                    );
                }

                $line->update(['counted_quantity' => $countedQuantity]);
                $result = $line->fresh();
            } else {
                $result = StocktakeLine::query()->create([
                    'organization_id' => $line->organization_id,
                    'stocktake_id' => $line->stocktake_id,
                    'asset_id' => $line->asset_id,
                    'expected_quantity' => $line->expected_quantity,
                    'counted_quantity' => $countedQuantity,
                    'recount_of_line_id' => $line->id,
                ]);
            }

            $this->auditLogger->log(
                action: $isRecount ? 'assets.stocktake.line_recounted' : 'assets.stocktake.line_counted',
                target: $result,
                after: ['counted_quantity' => $countedQuantity],
                actor: $actor,
            );

            return $result;
        });
    }
}
