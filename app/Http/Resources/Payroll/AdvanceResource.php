<?php

namespace App\Http\Resources\Payroll;

use App\Domain\Payroll\Models\Advance;
use App\Domain\Payroll\Services\PayrollBalanceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Advance */
class AdvanceResource extends JsonResource
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
            'reason' => $this->reason,
            'status' => $this->status,
            'granted_at' => $this->granted_at->toIso8601String(),
            'remaining' => app(PayrollBalanceService::class)->remainingOnAdvance($this->resource),
        ];
    }
}
