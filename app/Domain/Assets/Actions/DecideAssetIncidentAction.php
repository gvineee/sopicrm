<?php

namespace App\Domain\Assets\Actions;

use App\Domain\Assets\Exceptions\InvalidCustodyStateException;
use App\Domain\Assets\Exceptions\StaleApprovalVersionException;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetIncident;
use App\Domain\Shared\Models\Approval;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ASSETS-01, spec 9.5: "Write-off requires an authorized approval record...
 * and never deletes the asset." `StaleApprovalVersionException`'s own
 * docblock names exactly this action ("applied to write-off approval") —
 * `$targetVersion` must match the AssetIncident's current `version` (spec
 * section 19 optimistic-concurrency rule) before any decision is recorded,
 * for every decision type, not only write-off.
 *
 * `decision = 'approved'`/`'rejected'` on the created `Approval` row (never
 * a module-specific string) — the same shared `approvals.decision`
 * enum('approved','rejected') every other module writes; JOURNAL-01 (this
 * same session) found and fixed a real 500 caused by writing a
 * module-specific value into that column, so this action copies the
 * corrected pattern directly rather than repeating that bug. A `repair` or
 * `write_off` decision is always recorded as `approved` (someone in
 * authority reviewed and acted on the incident); `no_action` gets no
 * Approval row at all — nothing was actually approved or rejected, the
 * incident was simply reviewed and closed with no consequence.
 */
class DecideAssetIncidentAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(AssetIncident $incident, int $targetVersion, string $decision, User $actor, ?string $reason = null): AssetIncident
    {
        return DB::transaction(function () use ($incident, $targetVersion, $decision, $actor, $reason) {
            $incident = AssetIncident::whereKey($incident->id)->lockForUpdate()->firstOrFail();

            if ($incident->decision !== null) {
                throw InvalidCustodyStateException::forTransition('decided', $decision);
            }

            if ($incident->version !== $targetVersion) {
                throw StaleApprovalVersionException::make($targetVersion, $incident->version);
            }

            $before = $incident->only(['decision']);

            $incident->fill([
                'decision' => $decision,
                'reviewed_by_user_id' => $actor->id,
                'decided_at' => now(),
            ])->save();

            if (in_array($decision, ['repair', 'write_off'], true)) {
                Approval::query()->create([
                    'approvable_type' => $incident->getMorphClass(),
                    'approvable_id' => $incident->id,
                    'target_version' => $targetVersion,
                    'approver_user_id' => $actor->id,
                    'decision' => 'approved',
                    'reason' => $reason,
                    'decided_at' => now(),
                ]);
            }

            if ($decision === 'write_off') {
                // Never deletes the Asset row (spec 9.5 explicit) — history
                // remains, including every prior custody_transaction.
                Asset::whereKey($incident->asset_id)->lockForUpdate()->update(['condition' => 'written_off']);
            } elseif ($decision === 'repair') {
                Asset::whereKey($incident->asset_id)->lockForUpdate()->update(['condition' => 'under_repair']);
            }

            $this->auditLogger->log(
                action: 'assets.incident.decided',
                target: $incident,
                before: $before,
                after: $incident->only(['decision']),
                reason: $reason,
                actor: $actor,
            );

            return $incident->fresh();
        });
    }
}
