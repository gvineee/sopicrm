<?php

namespace App\Domain\Assets\Support;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetActiveCustody;
use Illuminate\Database\QueryException;

/**
 * The concrete "transaction + lock/unique constraint" mechanism spec section
 * 9.6 requires ("ერთდროულად ორი გაცემიდან მხოლოდ ერთმა უნდა შეძლოს იგივე
 * აქტივის დაჯავშნა/გაცემა"): `lockRow()` must be called inside an ALREADY
 * OPEN DB transaction (every caller wraps its work in DB::transaction), and
 * takes a real `SELECT ... FOR UPDATE` row lock on the asset's
 * `asset_active_custody` marker row before the caller inspects/changes its
 * status — so a second, concurrent transaction attempting the same asset
 * blocks at the database level until the first commits or rolls back, then
 * sees the first transaction's committed status change. The partial unique
 * index `asset_active_custody_locked_unique` (status IN ('issued',
 * 'awaiting_receipt')) is the DB-level backstop even if a bug ever bypassed
 * this row lock.
 *
 * Only meaningful for individually-tracked assets (`tracking_type` IN
 * ('individual','kit_component')) — quantity/consumable assets are never
 * exclusively locked here (multiple people legitimately hold *some*
 * quantity concurrently); their concurrency guard is a row lock directly on
 * `assets.quantity_on_hand` inside the same transaction (see
 * FinalizeIssueAction), documented in docs/decisions.md.
 */
class CustodyLockManager
{
    /**
     * Fetches (creating on first use) and FOR-UPDATE-locks the asset's
     * active-custody marker row. Must run inside an open DB transaction.
     */
    public function lockRow(Asset $asset): AssetActiveCustody
    {
        $existing = AssetActiveCustody::query()
            ->where('asset_id', $asset->id)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            AssetActiveCustody::query()->create([
                'asset_id' => $asset->id,
                'status' => 'available',
            ]);
        } catch (QueryException) {
            // Unique-constraint race: another concurrent request created the
            // marker row first. Fall through and re-fetch+lock it below —
            // this is the exact scenario the DB-level backstop exists for.
        }

        return AssetActiveCustody::query()
            ->where('asset_id', $asset->id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
