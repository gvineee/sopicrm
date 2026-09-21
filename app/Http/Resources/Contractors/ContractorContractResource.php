<?php

namespace App\Http\Resources\Contractors;

use App\Domain\Contractors\Models\ContractorContract;
use App\Domain\Contractors\Services\ContractorBalanceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ContractorContract */
class ContractorContractResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contractor_id' => $this->contractor_id,
            'project' => $this->whenLoaded('project', fn () => $this->project === null ? null : [
                'id' => $this->project->id,
                'name' => $this->project->name,
            ]),
            'contract_number' => $this->contract_number,
            'title' => $this->title,
            'description' => $this->description,
            'rate_type' => $this->rate_type,
            'rate_amount' => $this->rate_amount,
            'total_amount' => $this->total_amount,
            'currency' => $this->currency,
            'unit' => $this->unit,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'status' => $this->status,
            'submitted_by' => $this->whenLoaded('submittedBy', fn () => $this->submittedBy?->only(['id', 'name'])),
            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy?->only(['id', 'name'])),
            'rejection_reason' => $this->rejection_reason,
            'terms' => $this->terms,
            'outstanding_balance' => $this->when(
                $this->status === 'active' || $this->status === 'closed',
                fn () => app(ContractorBalanceService::class)->outstanding($this->resource),
            ),
            'version' => $this->version,
        ];
    }
}
