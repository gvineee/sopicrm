<?php

namespace App\Http\Resources\Assets;

use App\Domain\Assets\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Asset */
class AssetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'tracking_type' => $this->tracking_type,
            'inventory_code' => $this->inventory_code,
            'condition' => $this->condition,
            'brand' => $this->brand,
            'model' => $this->model,
            'serial_number' => $this->serial_number,
            'ownership' => $this->ownership,
            'quantity_on_hand' => $this->quantity_on_hand,
            'qr_token' => $this->qr_token,
            'active_custody_status' => $this->whenLoaded('activeCustody', fn () => $this->activeCustody?->status),
            'current_location' => $this->whenLoaded('currentLocation', fn () => $this->currentLocation === null ? null : [
                'locatable_type' => $this->currentLocation->locatable_type,
                'locatable_id' => $this->currentLocation->locatable_id,
                'as_of' => $this->currentLocation->as_of->toIso8601String(),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
