<?php

namespace App\Http\Resources\Assets;

use App\Domain\Assets\Models\AssetIncident;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AssetIncident */
class AssetIncidentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'incident_type' => $this->incident_type,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'location' => $this->location,
            'description' => $this->description,
            'estimated_repair_cost' => $this->estimated_repair_cost,
            'reported_by_name' => $this->whenLoaded('reportedBy', fn () => $this->reportedBy?->name),
            'reviewed_by_name' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy?->name),
            'decision' => $this->decision,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
