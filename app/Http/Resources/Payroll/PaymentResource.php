<?php

namespace App\Http\Resources\Payroll;

use App\Domain\Payroll\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn () => trim($this->employee->first_name.' '.$this->employee->last_name)),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'method' => $this->method,
            'reference' => $this->reference,
            'status' => $this->status,
            'paid_at' => $this->paid_at->toIso8601String(),
        ];
    }
}
