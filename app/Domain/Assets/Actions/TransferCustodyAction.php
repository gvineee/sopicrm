<?php

namespace App\Domain\Assets\Actions;

use App\Domain\Assets\Exceptions\AssetNotAvailableException;
use App\Domain\Assets\Exceptions\InvalidCustodyStateException;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetActiveCustody;
use App\Domain\Assets\Models\CustodyLine;
use App\Domain\Assets\Models\CustodyTransaction;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ASSETS-01, spec 9.4 (docs/data-model.md line 192): direct
 * employee-to-employee (or employee-to-warehouse) transfer, recording a
 * full chain — both `issuing_employee_id` and `receiving_employee_id` (or
 * `receiving_warehouse_id`) populated on ONE new `type='transfer'`
 * CustodyTransaction row, never a silent `asset_locations` edit.
 *
 * Judgment call, documented: unlike `issue` (SaveIssueDraftAction /
 * FinalizeIssueAction's own two-step draft/finalize split, per
 * SaveIssueDraftAction's explicit docblock), a transfer is recorded in one
 * step here — only `issue` is named as needing a savable draft anywhere in
 * this domain's existing code/spec, and a transfer is a much shorter-lived,
 * single-actor action (the current holder handing an already-issued asset
 * on) with no evident need for a multi-session draft. Only individually-
 * tracked assets (`individual`/`kit_component`) that are currently `issued`
 * may be transferred this way — a `quantity`/`consumable` asset has no
 * single holder to transfer FROM by definition and is out of this action's
 * scope (its "transfer" is just a normal return + re-issue of some
 * quantity, already covered by ReturnCustodyAction + a new issue).
 */
class TransferCustodyAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{receiving_employee_id?: ?string, receiving_warehouse_id?: ?string, project_id?: ?string, occurred_at?: ?string, condition_at_transaction?: string, accessories_note?: ?string, photo_attachment_ids?: ?list<string>, comment?: ?string}  $data
     */
    public function execute(Asset $asset, array $data, User $actor): CustodyTransaction
    {
        return DB::transaction(function () use ($asset, $data, $actor) {
            /** @var Asset $asset */
            $asset = Asset::whereKey($asset->id)->lockForUpdate()->firstOrFail();

            if (! in_array($asset->tracking_type, ['individual', 'kit_component'], true)) {
                throw AssetNotAvailableException::forCondition($asset->id, $asset->tracking_type);
            }

            if (in_array($asset->condition, ['under_repair', 'written_off'], true)) {
                throw AssetNotAvailableException::forCondition($asset->id, $asset->condition);
            }

            /** @var AssetActiveCustody $marker */
            $marker = AssetActiveCustody::query()->where('asset_id', $asset->id)->lockForUpdate()->firstOrFail();

            if ($marker->status !== 'issued') {
                throw InvalidCustodyStateException::forTransition($marker->status, 'in_transit');
            }

            $currentTransaction = CustodyTransaction::query()->findOrFail($marker->current_custody_transaction_id);

            $transaction = CustodyTransaction::query()->create([
                'type' => 'transfer',
                'issuing_employee_id' => $currentTransaction->receiving_employee_id,
                'receiving_employee_id' => $data['receiving_employee_id'] ?? null,
                'receiving_warehouse_id' => $data['receiving_warehouse_id'] ?? null,
                'project_id' => $data['project_id'] ?? $currentTransaction->project_id,
                'occurred_at' => $data['occurred_at'] ?? now(),
                'condition_at_transaction' => $data['condition_at_transaction'] ?? 'good',
                'accessories_note' => $data['accessories_note'] ?? null,
                'photo_attachment_ids' => $data['photo_attachment_ids'] ?? [],
                'comment' => $data['comment'] ?? null,
                'issued_by_user_id' => $actor->id,
                'status' => 'in_transit',
            ]);

            CustodyLine::query()->create([
                'custody_transaction_id' => $transaction->id,
                'asset_id' => $asset->id,
                'quantity' => 1,
            ]);

            $marker->update([
                'status' => 'in_transit',
                'current_custody_transaction_id' => $transaction->id,
            ]);

            $this->auditLogger->log(
                action: 'assets.custody.transfer_recorded',
                target: $transaction,
                after: $transaction->only(['type', 'status', 'issuing_employee_id', 'receiving_employee_id']),
                actor: $actor,
            );

            return $transaction->fresh('lines.asset');
        });
    }
}
