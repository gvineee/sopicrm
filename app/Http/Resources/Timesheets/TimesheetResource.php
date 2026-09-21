<?php

namespace App\Http\Resources\Timesheets;

use App\Domain\Attendance\Models\Timesheet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Timesheet */
class TimesheetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn () => trim($this->employee->first_name.' '.$this->employee->last_name)),
            'pay_period_id' => $this->pay_period_id,
            'pay_period' => $this->whenLoaded('payPeriod', fn () => [
                'starts_on' => $this->payPeriod->starts_on->toDateString(),
                'ends_on' => $this->payPeriod->ends_on->toDateString(),
            ]),
            'status' => $this->status,
            'version' => $this->version,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_reason' => $this->rejected_reason,
            'locked_at' => $this->locked_at?->toIso8601String(),
            'lines' => TimesheetLineResource::collection($this->whenLoaded('lines')),
            'lines_sum_payable_minutes' => $this->lines_sum_payable_minutes ?? null,
            'total_payable_minutes' => $this->whenLoaded('lines', fn () => $this->lines->sum('payable_minutes')),
        ];
    }
}
