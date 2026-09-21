<?php

namespace App\Domain\Assets\Actions;

use App\Domain\Assets\Exceptions\InvalidCustodyStateException;
use App\Domain\Assets\Models\Stocktake;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Stocktake: closes a session only once every line is resolved — every
 * line must carry a real count (an uncounted line means the physical count
 * session isn't actually finished), and any line whose count varies from
 * its expectation must carry a real `variance_approved_adjustment_id`.
 * This is the enforcement point for "you cannot close a stocktake with an
 * unresolved variance silently ignored" — a superseded (recounted)
 * original line is excluded from this check, only the latest count for
 * each asset matters.
 */
class CompleteStocktakeAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(Stocktake $stocktake, User $actor): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $actor) {
            $stocktake = Stocktake::whereKey($stocktake->id)->lockForUpdate()->firstOrFail();

            if ($stocktake->status !== 'in_progress') {
                throw InvalidCustodyStateException::forTransition($stocktake->status, 'completed');
            }

            $lines = $stocktake->lines()->get();
            $latestLinesByAsset = $lines
                ->reject(fn ($line) => $lines->contains('recount_of_line_id', $line->id))
                ->groupBy('asset_id')
                ->map(fn ($group) => $group->sortByDesc('created_at')->first());

            foreach ($latestLinesByAsset as $line) {
                if ($line->counted_quantity === null) {
                    throw new InvalidCustodyStateException(
                        "დათვლა დაუსრულებელია — არსებობს პოზიცია, რომელიც ჯერ არ დათვლილა (asset: {$line->asset_id})."
                    );
                }

                $hasVariance = bccomp(
                    number_format((float) $line->counted_quantity, 2, '.', ''),
                    number_format((float) $line->expected_quantity, 2, '.', ''),
                    2,
                ) !== 0;

                if ($hasVariance && $line->variance_approved_adjustment_id === null) {
                    throw new InvalidCustodyStateException(
                        "დაუმტკიცებელი ვარიაცია რჩება — ჯერ დაამტკიცეთ ყველა განსხვავება (asset: {$line->asset_id})."
                    );
                }
            }

            $before = $stocktake->only(['status']);
            $stocktake->update(['status' => 'completed']);

            $this->auditLogger->log(
                action: 'assets.stocktake.completed',
                target: $stocktake,
                before: $before,
                after: $stocktake->only(['status']),
                actor: $actor,
            );

            return $stocktake->fresh();
        });
    }
}
