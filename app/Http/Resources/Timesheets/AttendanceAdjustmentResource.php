<?php

namespace App\Http\Resources\Timesheets;

use App\Domain\Attendance\Models\AttendanceAdjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceAdjustment */
class AttendanceAdjustmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn () => trim($this->employee->first_name.' '.$this->employee->last_name)),
            'work_date' => $this->work_date->toDateString(),
            'corrected_clock_in_at' => $this->corrected_clock_in_at?->toIso8601String(),
            'corrected_clock_out_at' => $this->corrected_clock_out_at?->toIso8601String(),
            'corrected_hours' => $this->corrected_hours,
            'reason' => $this->reason,
            'status' => $this->status,
            'version' => $this->version,
            'for_locked_period' => $this->for_locked_period,
        ];
    }
}
