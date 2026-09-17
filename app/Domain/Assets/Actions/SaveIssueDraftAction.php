<?php

namespace App\Domain\Assets\Actions;

use App\Domain\Assets\Exceptions\InvalidCustodyStateException;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetKit;
use App\Domain\Assets\Models\CustodyLine;
use App\Domain\Assets\Models\CustodyTransaction;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * spec 9.2: "Draft შეიძლება შეინახოს" — creates or updates a `draft` issue
 * transaction. Finalizing (FinalizeIssueAction) is a separate, deliberate
 * step that is the only place stock/locks actually change — saving or
 * re-saving a draft never touches asset availability.
 *
 * Issuing a kit automatically expands into one CustodyLine per registered
 * component (App\Domain\Assets\Models\AssetKit), so a later partial return
 * can track each component's own condition/remaining obligation
 * independently (spec 9.3).
 */
class SaveIssueDraftAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{receiving_employee_id?: ?string, receiving_warehouse_id?: ?string, project_id?: ?string, occurred_at?: ?string, expected_return_at?: ?string, condition_at_transaction?: string, accessories_note?: ?string, photo_attachment_ids?: ?list<string>, comment?: ?string, lines: list<array{asset_id: string, quantity?: float}>}  $data
     */
    public function execute(?CustodyTransaction $existing, array $data, User $actor): CustodyTransaction
    {
        return DB::transaction(function () use ($existing, $data, $actor) {
            if ($existing !== null) {
                $existing = CustodyTransaction::whereKey($existing->id)->lockForUpdate()->firstOrFail();

                if ($existing->status !== 'draft') {
                    throw InvalidCustodyStateException::forTransition($existing->status, 'draft');
                }
            }

            $attributes = [
                'type' => 'issue',
                'receiving_employee_id' => $data['receiving_employee_id'] ?? null,
                'receiving_warehouse_id' => $data['receiving_warehouse_id'] ?? null,
                'project_id' => $data['project_id'] ?? null,
                'occurred_at' => $data['occurred_at'] ?? now(),
                'expected_return_at' => $data['expected_return_at'] ?? null,
                'condition_at_transaction' => $data['condition_at_transaction'] ?? 'good',
                'accessories_note' => $data['accessories_note'] ?? null,
                'photo_attachment_ids' => $data['photo_attachment_ids'] ?? [],
                'comment' => $data['comment'] ?? null,
                'issued_by_user_id' => $actor->id,
                'status' => 'draft',
            ];

            if ($existing === null) {
                $transaction = CustodyTransaction::query()->create($attributes);
            } else {
                $existing->update($attributes);
                $existing->lines()->delete();
                $transaction = $existing;
            }

            $this->createLinesExpandingKits($transaction, $data['lines']);

            $this->auditLogger->log(
                action: $existing === null ? 'assets.custody.issue_draft_created' : 'assets.custody.issue_draft_updated',
                target: $transaction,
                after: $transaction->only(['type', 'status', 'receiving_employee_id']),
                actor: $actor,
            );

            return $transaction->fresh('lines.asset');
        });
    }

    /**
     * @param  list<array{asset_id: string, quantity?: float}>  $lines
     */
    private function createLinesExpandingKits(CustodyTransaction $transaction, array $lines): void
    {
        foreach ($lines as $line) {
            $asset = Asset::query()->findOrFail($line['asset_id']);
            $quantity = $line['quantity'] ?? 1;

            CustodyLine::query()->create([
                'custody_transaction_id' => $transaction->id,
                'asset_id' => $asset->id,
                'quantity' => $quantity,
            ]);

            $componentAssetIds = AssetKit::query()
                ->where('kit_asset_id', $asset->id)
                ->pluck('component_asset_id');

            foreach ($componentAssetIds as $componentAssetId) {
                CustodyLine::query()->create([
                    'custody_transaction_id' => $transaction->id,
                    'asset_id' => $componentAssetId,
                    'quantity' => 1,
                ]);
            }
        }
    }
}
