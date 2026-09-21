<?php

namespace App\Http\Resources\Contractors;

use App\Domain\Contractors\Models\Contractor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Contractor */
class ContractorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'tax_id' => $this->tax_id,
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'email' => $this->email,
            'default_currency' => $this->default_currency,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'contracts_count' => $this->whenCounted('contracts'),
        ];
    }
}
