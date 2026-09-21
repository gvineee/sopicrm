<?php

namespace App\Domain\Assets\Actions;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetIncident;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;

/**
 * ASSETS-01, spec 9.5. Reporting an incident (damage/loss/write-off
 * request) never itself changes the asset's `condition` — only
 * DecideAssetIncidentAction's review decision does, so a report can never
 * be used to silently quarantine an asset before anyone has actually looked
 * at it.
 */
class ReportAssetIncidentAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{incident_type: string, occurred_at?: ?string, location?: ?string, description: string, photo_attachment_ids?: ?list<string>, estimated_repair_cost?: ?string}  $data
     */
    public function execute(Asset $asset, array $data, User $actor): AssetIncident
    {
        $incident = AssetIncident::query()->create([
            'asset_id' => $asset->id,
            'incident_type' => $data['incident_type'],
            'occurred_at' => $data['occurred_at'] ?? now(),
            'location' => $data['location'] ?? null,
            'description' => $data['description'],
            'photo_attachment_ids' => $data['photo_attachment_ids'] ?? [],
            'reported_by_user_id' => $actor->id,
            'estimated_repair_cost' => $data['estimated_repair_cost'] ?? null,
        ]);

        $this->auditLogger->log(
            action: 'assets.incident.reported',
            target: $incident,
            after: $incident->only(['incident_type', 'description']),
            actor: $actor,
        );

        return $incident->fresh();
    }
}
