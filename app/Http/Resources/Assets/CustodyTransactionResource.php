<?php

namespace App\Http\Resources\Assets;

use App\Domain\Assets\Models\CustodyTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CustodyTransaction */
class CustodyTransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'version' => $this->version,
            'issuing_employee_id' => $this->issuing_employee_id,
            'receiving_employee_id' => $this->receiving_employee_id,
            'receiving_employee_name' => $this->whenLoaded('receivingEmployee', fn () => $this->receivingEmployee === null ? null : trim($this->receivingEmployee->first_name.' '.$this->receivingEmployee->last_name)),
            'receiving_warehouse_id' => $this->receiving_warehouse_id,
            'project_id' => $this->project_id,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'expected_return_at' => $this->expected_return_at?->toIso8601String(),
            'return_requested_at' => $this->return_requested_at?->toIso8601String(),
            'condition_at_transaction' => $this->condition_at_transaction,
            'accessories_note' => $this->accessories_note,
            'comment' => $this->comment,
            'received_confirmation_at' => $this->received_confirmation_at?->toIso8601String(),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'asset_id' => $line->asset_id,
                'asset_name' => $line->asset?->name,
                'inventory_code' => $line->asset?->inventory_code,
                'quantity' => $line->quantity,
                'returned_quantity' => $line->returned_quantity,
                'outstanding' => (float) $line->quantity - (float) $line->returned_quantity,
                'line_condition' => $line->line_condition,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
