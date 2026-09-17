<?php

namespace App\Domain\Employees\DataTransferObjects;

use App\Domain\Assets\Models\CustodyLine;
use App\Domain\Assets\Models\CustodyTransaction;
use Illuminate\Support\Collection;

/**
 * Result of App\Domain\Employees\Actions\TerminateEmploymentAction — surfaced
 * to the caller (controller/UI) so HR sees exactly what happened and what
 * still needs a human decision. Nothing here is itself a financial write;
 * `unreturnedCustodyTransactions` is informational only (hard constraint:
 * "თანამშრომლის გამორთვა არ ნიშნავს დაკარგული ხელსაწყოს ღირებულების
 * ავტომატურ ჩამოჭრას" — turning it into a cost deduction requires a separate,
 * human-approved Assets-module action).
 */
final class TerminationOutcome
{
    /**
     * @param  Collection<int, CustodyTransaction>  $unreturnedCustodyTransactions
     */
    public function __construct(
        public readonly bool $loginRevoked,
        public readonly int $deviceSyncCommandsScheduled,
        public readonly Collection $unreturnedCustodyTransactions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'login_revoked' => $this->loginRevoked,
            'device_sync_commands_scheduled' => $this->deviceSyncCommandsScheduled,
            'unreturned_custody_transactions' => $this->unreturnedCustodyTransactions
                ->map(fn (CustodyTransaction $transaction): array => [
                    'id' => $transaction->id,
                    'type' => $transaction->type,
                    'occurred_at' => $transaction->occurred_at->toIso8601String(),
                    'expected_return_at' => $transaction->expected_return_at?->toIso8601String(),
                    'lines' => $transaction->lines->map(fn (CustodyLine $line): array => [
                        'asset_id' => $line->asset_id,
                        'asset_name' => $line->asset?->name,
                        'outstanding_quantity' => bcsub((string) $line->quantity, (string) $line->returned_quantity, 2),
                    ])->values()->all(),
                ])
                ->values()->all(),
        ];
    }
}
