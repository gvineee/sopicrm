<?php

namespace App\Http\Resources\Payroll;

use App\Domain\Payroll\Models\PayRunLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PayRunLine */
class PayRunLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn () => trim($this->employee->first_name.' '.$this->employee->last_name)),
            'project_id' => $this->project_id,
            'project_name' => $this->whenLoaded('project', fn () => $this->project?->name),
            'basis' => $this->basis,
            'quantity' => $this->quantity,
            'formula_applied' => $this->formula_applied,
            'gross_amount' => $this->gross_amount,
            'adjustments_amount' => $this->adjustments_amount,
            'net_amount' => $this->net_amount,
            'exceeds_daily_cap' => $this->exceeds_daily_cap,
        ];
    }
}
