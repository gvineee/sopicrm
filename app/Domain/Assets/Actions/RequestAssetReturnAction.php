<?php

namespace App\Domain\Assets\Actions;

use App\Domain\Assets\Exceptions\InvalidCustodyStateException;
use App\Domain\Assets\Models\CustodyTransaction;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;

/**
 * ASSETS-01, spec section 9.6 employee self-service "request a return"
 * (`CustodyTransaction.return_requested_at`/`_by_user_id`,
 * 2026_09_16_150000_add_return_request_to_custody_transactions_table.php).
 * Per the model's own docblock this is "purely informational/notification-
 * triggering, never itself a return" — it never touches
 * `AssetActiveCustody`/`asset_locations`/`custody_lines`; only
 * ReturnCustodyAction actually moves custody.
 */
class RequestAssetReturnAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(CustodyTransaction $transaction, User $actor): CustodyTransaction
    {
        if (! in_array($transaction->status, ['issued', 'partially_returned'], true)) {
            throw InvalidCustodyStateException::forTransition($transaction->status, 'return_requested');
        }

        $transaction->fill([
            'return_requested_at' => now(),
            'return_requested_by_user_id' => $actor->id,
        ])->save();

        $this->auditLogger->log(
            action: 'assets.custody.return_requested',
            target: $transaction,
            after: $transaction->only(['return_requested_at', 'return_requested_by_user_id']),
            actor: $actor,
        );

        return $transaction->fresh();
    }
}
