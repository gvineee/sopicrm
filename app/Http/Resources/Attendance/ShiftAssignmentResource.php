<?php

namespace App\Http\Resources\Attendance;

use App\Domain\Attendance\Models\ShiftAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ShiftAssignment */
class ShiftAssignmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn () => trim($this->employee->first_name.' '.$this->employee->last_name)),
            'shift_template_id' => $this->shift_template_id,
            'shift_template_name' => $this->whenLoaded('shiftTemplate', fn () => $this->shiftTemplate?->name),
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
        ];
    }
}
