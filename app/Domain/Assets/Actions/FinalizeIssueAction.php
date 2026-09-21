<?php

namespace App\Domain\Assets\Actions;

use App\Domain\Assets\Exceptions\AssetAlreadyIssuedException;
use App\Domain\Assets\Exceptions\AssetNotAvailableException;
use App\Domain\Assets\Exceptions\InsufficientQuantityException;
use App\Domain\Assets\Exceptions\InvalidCustodyStateException;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\CustodyLine;
use App\Domain\Assets\Models\CustodyTransaction;
use App\Domain\Assets\Support\CustodyLockManager;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ASSETS-01: the "Finalizing (FinalizeIssueAction) is a separate, deliberate
 * step that is the only place stock/locks actually change" step
 * SaveIssueDraftAction's own docblock already names. Transitions a `draft`
 * `issue` CustodyTransaction to `awaiting_receipt` (spec state machine:
 * draft -> awaiting_receipt -> issued -> partially_returned/returned, per
 * InvalidCustodyStateException's own docblock) — every issue passes through
 * digital receipt confirmation (ConfirmCustodyReceiptAction) before it is
 * considered truly `issued`; this action never sets status straight to
 * `issued` itself.
 *
 * Concurrency (spec 9.6 hard rule): for an individually-tracked asset
 * (`individual`/`kit_component`), CustodyLockManager::lockRow() takes a real
 * `SELECT ... FOR UPDATE` on the asset's `asset_active_custody` marker row
 * before its status is inspected, so of two concurrent finalize attempts on
 * the same asset only one can observe `status = 'available'`; the other
 * blocks, then sees the first transaction's committed change and throws
 * AssetAlreadyIssuedException. For `quantity`/`consumable` assets, the guard
 * is a direct `SELECT ... FOR UPDATE` on the `assets` row itself (no
 * exclusive marker — multiple people may legitimately hold *some* quantity
 * concurrently), decrementing `quantity_on_hand` inside the same lock.
 */
class FinalizeIssueAction
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly CustodyLockManager $lockManager,
    ) {}

    public function execute(CustodyTransaction $transaction, User $actor): CustodyTransaction
    {
        return DB::transaction(function () use ($transaction, $actor) {
            $transaction = CustodyTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($transaction->type !== 'issue' || $transaction->status !== 'draft') {
                throw InvalidCustodyStateException::forTransition($transaction->status, 'awaiting_receipt');
            }

            $lines = $transaction->lines()->get();

            if ($lines->isEmpty()) {
                throw InvalidCustodyStateException::forTransition('draft', 'awaiting_receipt');
            }

            foreach ($lines as $line) {
                $this->reserveLine($line, $transaction);
            }

            $before = $transaction->only(['status']);
            $transaction->fill(['status' => 'awaiting_receipt'])->save();

            $this->auditLogger->log(
                action: 'assets.custody.issue_finalized',
                target: $transaction,
                before: $before,
                after: $transaction->only(['status']),
                actor: $actor,
            );

            return $transaction->fresh('lines.asset');
        });
    }

    private function reserveLine(CustodyLine $line, CustodyTransaction $transaction): void
    {
        /** @var Asset $asset */
        $asset = Asset::whereKey($line->asset_id)->lockForUpdate()->firstOrFail();

        if (in_array($asset->condition, ['under_repair', 'written_off'], true)) {
            throw AssetNotAvailableException::forCondition($asset->id, $asset->condition);
        }

        if (in_array($asset->tracking_type, ['individual', 'kit_component'], true)) {
            $marker = $this->lockManager->lockRow($asset);

            if ($marker->status !== 'available') {
                throw AssetAlreadyIssuedException::forAsset($asset->id);
            }

            $marker->update([
                'status' => 'awaiting_receipt',
                'current_custody_transaction_id' => $transaction->id,
            ]);

            return;
        }

        // quantity/consumable: no exclusive marker, direct row-locked
        // decrement (CustodyLockManager's own docblock — "their concurrency
        // guard is a row lock directly on assets.quantity_on_hand").
        $available = (float) $asset->quantity_on_hand;
        $requested = (float) $line->quantity;

        if ($requested > $available) {
            throw InsufficientQuantityException::forAsset($asset->id, $requested, $available);
        }

        $asset->decrement('quantity_on_hand', $requested);
    }
}
