<?php

namespace App\Http\Resources\Employees;

use App\Domain\Employees\Models\RateHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RateHistory
 */
class RateHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RateHistory $rate */
        $rate = $this->resource;

        return [
            'id' => $rate->id,
            'employee_id' => $rate->employee_id,
            'project_id' => $rate->project_id,
            'project_name' => $this->whenLoaded('project', fn () => $rate->project?->name),
            'rate_type' => $rate->rate_type,
            'amount' => (string) $rate->amount,
            'currency' => $rate->currency,
            'effective_from' => $rate->effective_from->toDateString(),
            'effective_to' => $rate->effective_to?->toDateString(),
            'change_reason' => $rate->change_reason,
            'approved_by' => $this->whenLoaded('approvedBy', fn () => $rate->approvedBy?->name),
            'is_base_rate' => $rate->project_id === null,
            'created_at' => $rate->created_at?->toIso8601String(),
        ];
    }
}
