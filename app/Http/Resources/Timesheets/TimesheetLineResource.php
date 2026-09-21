<?php

namespace App\Http\Resources\Timesheets;

use App\Domain\Attendance\Models\TimesheetLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TimesheetLine */
class TimesheetLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_date' => $this->work_date->toDateString(),
            'project_id' => $this->project_id,
            'project_name' => $this->whenLoaded('project', fn () => $this->project?->name),
            'attendance_session_id' => $this->attendance_session_id,
            'payable_minutes' => $this->payable_minutes,
            'rate_type' => $this->rate_type,
            'rate_snapshot_id' => $this->rate_snapshot_id,
        ];
    }
}
