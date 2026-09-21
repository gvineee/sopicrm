<?php

namespace App\Domain\Assets\Actions;

use App\Domain\Assets\Exceptions\InsufficientQuantityException;
use App\Domain\Assets\Exceptions\InvalidCustodyStateException;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetActiveCustody;
use App\Domain\Assets\Models\AssetLocation;
use App\Domain\Assets\Models\CustodyLine;
use App\Domain\Assets\Models\CustodyTransaction;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ASSETS-01 — the exact `ReturnCustodyAction` name `CustodyTransaction`'s
 * own docblock already references. Full or partial return against an
 * `issued`/`partially_returned` transaction. Immutable-history rule: this
 * NEVER edits the original issue/transfer transaction's own lines beyond
 * incrementing `returned_quantity` (the running "how much has come back"
 * counter spec 9.6 names) — the actual return event is always its OWN new
 * `type='return'` CustodyTransaction + CustodyLine rows, so the full chain
 * of who-had-what-when is reconstructable from immutable rows alone.
 *
 * A `damaged` return condition routes the asset's `condition` to
 * `under_repair` rather than back to `good` (spec 9.3, `docs/data-model.md`
 * line 197, explicit) — only meaningful for individually-tracked assets;
 * a quantity/consumable line returning "damaged" still routes THAT asset's
 * shared `condition` field the same way (a judgment call: this domain has
 * no per-unit condition tracking for bulk consumables, so any damaged
 * partial return quarantines the whole SKU for review rather than silently
 * dropping the signal).
 */
class ReturnCustodyAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  list<array{custody_line_id: string, quantity: float, condition?: ?string}>  $lines
     */
    public function execute(CustodyTransaction $transaction, array $lines, User $actor, ?string $comment = null, ?string $receivingWarehouseId = null): CustodyTransaction
    {
        return DB::transaction(function () use ($transaction, $lines, $actor, $comment, $receivingWarehouseId) {
            $transaction = CustodyTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if (! in_array($transaction->status, ['issued', 'partially_returned'], true)) {
                throw InvalidCustodyStateException::forTransition($transaction->status, 'returned');
            }

            $returnTransaction = CustodyTransaction::query()->create([
                'type' => 'return',
                'issuing_employee_id' => $transaction->receiving_employee_id,
                'receiving_warehouse_id' => $receivingWarehouseId,
                'project_id' => $transaction->project_id,
                'occurred_at' => now(),
                'condition_at_transaction' => 'good',
                'comment' => $comment,
                'issued_by_user_id' => $actor->id,
                'status' => 'returned',
            ]);

            $allFullyReturned = true;

            foreach ($lines as $lineInput) {
                /** @var CustodyLine $originalLine */
                $originalLine = CustodyLine::whereKey($lineInput['custody_line_id'])->lockForUpdate()->firstOrFail();

                if ($originalLine->custody_transaction_id !== $transaction->id) {
                    throw InvalidCustodyStateException::forTransition('unrelated_line', 'returned');
                }

                $remaining = (float) $originalLine->quantity - (float) $originalLine->returned_quantity;
                $returning = (float) $lineInput['quantity'];

                if ($returning <= 0 || $returning > $remaining) {
                    throw InsufficientQuantityException::forAsset($originalLine->asset_id, $returning, $remaining);
                }

                $condition = $lineInput['condition'] ?? 'good';

                $originalLine->increment('returned_quantity', $returning);

                CustodyLine::query()->create([
                    'custody_transaction_id' => $returnTransaction->id,
                    'asset_id' => $originalLine->asset_id,
                    'quantity' => $returning,
                    'line_condition' => $condition,
                ]);

                /** @var Asset $asset */
                $asset = Asset::whereKey($originalLine->asset_id)->lockForUpdate()->firstOrFail();
                $damaged = $condition === 'damaged';

                if (in_array($asset->tracking_type, ['individual', 'kit_component'], true)) {
                    AssetActiveCustody::query()->where('asset_id', $asset->id)->update([
                        'status' => 'available',
                        'current_custody_transaction_id' => null,
                    ]);

                    AssetLocation::query()
                        ->where('asset_id', $asset->id)
                        ->where('is_current', true)
                        ->update(['is_current' => false]);

                    // `asset_locations.locatable_id` is NOT NULL, and there
                    // is no `warehouses` table to resolve a default from yet
                    // (P2 scope — 2026_09_16_090190_create_assets_domain_tables.php's
                    // own docblock). A real destination is only recorded
                    // when the return actually names one; otherwise the
                    // asset is left with no current location row at all —
                    // an honest "location unspecified" state (surfaced as
                    // such in the UI) rather than inventing a placeholder
                    // warehouse id.
                    if ($receivingWarehouseId !== null) {
                        AssetLocation::query()->create([
                            'locatable_type' => 'warehouse',
                            'locatable_id' => $receivingWarehouseId,
                            'asset_id' => $asset->id,
                            'as_of' => now(),
                            'is_current' => true,
                        ]);
                    }
                } else {
                    $asset->increment('quantity_on_hand', $returning);
                }

                if ($damaged) {
                    $asset->update(['condition' => 'under_repair']);
                }

                if (((float) $originalLine->fresh()->quantity - (float) $originalLine->fresh()->returned_quantity) > 0) {
                    $allFullyReturned = false;
                }
            }

            $before = $transaction->only(['status']);
            $transaction->fill(['status' => $allFullyReturned ? 'returned' : 'partially_returned'])->save();

            $this->auditLogger->log(
                action: 'assets.custody.returned',
                target: $returnTransaction,
                before: $before,
                after: ['original_transaction_status' => $transaction->status, 'return_transaction_id' => $returnTransaction->id],
                reason: $comment,
                actor: $actor,
            );

            return $returnTransaction->fresh('lines.asset');
        });
    }
}
