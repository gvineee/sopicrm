<?php

namespace App\Domain\Assets\Actions;

use App\Domain\Assets\Exceptions\InvalidCustodyStateException;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetActiveCustody;
use App\Domain\Assets\Models\AssetLocation;
use App\Domain\Assets\Models\CustodyTransaction;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ASSETS-01: `awaiting_receipt` (an issue) or `in_transit` (a transfer) ->
 * `issued`. Records ONLY `received_confirmation_user_id` + `_at` as
 * evidence — `docs/data-model.md` and `CustodyTransaction`'s own docblock
 * are explicit that this is NOT a qualified electronic signature; no UI
 * copy anywhere in this ticket claims otherwise.
 *
 * Moves each line's asset to its new current location (the receiving
 * employee, or the receiving warehouse for a warehouse-bound transfer) only
 * once receipt is actually confirmed — not at finalize/transfer time — so
 * `asset_locations`'s "exactly one current row" history reflects where the
 * asset is actually held, not merely where it was last dispatched to.
 */
class ConfirmCustodyReceiptAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(CustodyTransaction $transaction, User $actor): CustodyTransaction
    {
        return DB::transaction(function () use ($transaction, $actor) {
            $transaction = CustodyTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if (! in_array($transaction->status, ['awaiting_receipt', 'in_transit'], true)) {
                throw InvalidCustodyStateException::forTransition($transaction->status, 'issued');
            }

            $before = $transaction->only(['status']);

            $transaction->fill([
                'status' => 'issued',
                'received_confirmation_user_id' => $actor->id,
                'received_confirmation_at' => now(),
            ])->save();

            foreach ($transaction->lines()->get() as $line) {
                /** @var Asset $asset */
                $asset = Asset::whereKey($line->asset_id)->lockForUpdate()->firstOrFail();

                if (in_array($asset->tracking_type, ['individual', 'kit_component'], true)) {
                    AssetActiveCustody::query()->where('asset_id', $asset->id)->update([
                        'status' => 'issued',
                        'current_custody_transaction_id' => $transaction->id,
                    ]);
                }

                AssetLocation::query()
                    ->where('asset_id', $asset->id)
                    ->where('is_current', true)
                    ->update(['is_current' => false]);

                AssetLocation::query()->create([
                    'locatable_type' => $transaction->receiving_employee_id !== null ? 'employee' : 'warehouse',
                    'locatable_id' => $transaction->receiving_employee_id ?? $transaction->receiving_warehouse_id,
                    'asset_id' => $asset->id,
                    'as_of' => now(),
                    'is_current' => true,
                ]);
            }

            $this->auditLogger->log(
                action: 'assets.custody.receipt_confirmed',
                target: $transaction,
                before: $before,
                after: $transaction->only(['status', 'received_confirmation_user_id', 'received_confirmation_at']),
                actor: $actor,
            );

            return $transaction->fresh('lines.asset');
        });
    }
}
