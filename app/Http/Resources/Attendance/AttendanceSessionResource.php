<?php

namespace App\Http\Resources\Attendance;

use App\Domain\Attendance\Models\AttendanceSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceSession */
class AttendanceSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn () => trim($this->employee->first_name.' '.$this->employee->last_name)),
            'site_id' => $this->site_id,
            'site_name' => $this->whenLoaded('site', fn () => $this->site?->name),
            'project_id' => $this->project_id,
            'project_name' => $this->whenLoaded('project', fn () => $this->project?->name),
            'clock_in_at' => $this->clock_in_at->toIso8601String(),
            'clock_out_at' => $this->clock_out_at?->toIso8601String(),
            'work_date' => $this->work_date->toDateString(),
            'raw_duration_minutes' => $this->raw_duration_minutes,
            'payable_minutes' => $this->payable_minutes,
            'status' => $this->status,
            'anomalies_count' => $this->whenCounted('anomalies'),
        ];
    }
}
