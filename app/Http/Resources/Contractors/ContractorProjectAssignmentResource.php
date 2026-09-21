<?php

namespace App\Http\Resources\Contractors;

use App\Domain\Contractors\Models\ContractorProjectAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ContractorProjectAssignment */
class ContractorProjectAssignmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contractor' => $this->whenLoaded('contractor', fn () => $this->contractor->only(['id', 'name'])),
            'project_id' => $this->project_id,
            'contract_id' => $this->contract_id,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'scope_description' => $this->scope_description,
        ];
    }
}
