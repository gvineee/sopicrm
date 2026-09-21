<?php

namespace App\Http\Resources\Attendance;

use App\Domain\Attendance\Models\AttendanceAnomaly;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceAnomaly */
class AttendanceAnomalyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn () => $this->employee !== null ? trim($this->employee->first_name.' '.$this->employee->last_name) : null),
            'attendance_session_id' => $this->attendance_session_id,
            'device_id' => $this->device_id,
            'anomaly_type' => $this->anomaly_type,
            'detected_at' => $this->detected_at->toIso8601String(),
            'details' => $this->details,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolution_note' => $this->resolution_note,
        ];
    }
}
