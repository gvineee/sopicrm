<?php

namespace App\Http\Resources\Contractors;

use App\Domain\Contractors\Models\ContractorPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ContractorPayment */
class ContractorPaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_id' => $this->contract_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'paid_at' => $this->paid_at?->toDateString(),
            'method' => $this->method,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'recorded_by' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy?->only(['id', 'name'])),
        ];
    }
}
