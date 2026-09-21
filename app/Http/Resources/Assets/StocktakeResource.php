<?php

namespace App\Http\Resources\Assets;

use App\Domain\Assets\Models\Stocktake;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Stocktake
 */
class StocktakeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scope_type' => $this->scope_type,
            'scope_id' => $this->scope_id,
            'session_started_at' => $this->session_started_at?->toIso8601String(),
            'status' => $this->status,
            'performed_by_name' => $this->whenLoaded('performedBy', fn () => $this->performedBy?->name),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines
                ->reject(fn ($line) => $this->lines->contains('recount_of_line_id', $line->id))
                ->map(fn ($line) => [
                    'id' => $line->id,
                    'asset_id' => $line->asset_id,
                    'asset_name' => $line->asset?->name,
                    'inventory_code' => $line->asset?->inventory_code,
                    'expected_quantity' => (float) $line->expected_quantity,
                    'counted_quantity' => $line->counted_quantity !== null ? (float) $line->counted_quantity : null,
                    'has_variance' => $line->counted_quantity !== null
                        && bccomp(number_format((float) $line->counted_quantity, 2, '.', ''), number_format((float) $line->expected_quantity, 2, '.', ''), 2) !== 0,
                    'variance_resolved' => $line->variance_approved_adjustment_id !== null,
                    'recount_of_line_id' => $line->recount_of_line_id,
                ])
                ->values()),
        ];
    }
}
