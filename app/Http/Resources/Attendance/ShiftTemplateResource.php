<?php

namespace App\Http\Resources\Attendance;

use App\Domain\Attendance\Models\ShiftTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ShiftTemplate */
class ShiftTemplateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'site_name' => $this->whenLoaded('site', fn () => $this->site?->name),
            'name' => $this->name,
            'starts_at_local' => $this->starts_at_local,
            'ends_at_local' => $this->ends_at_local,
            'crosses_midnight' => $this->crosses_midnight,
            'scheduled_days' => $this->scheduled_days,
            'break_policy' => $this->break_policy,
            'allowed_late_minutes' => $this->allowed_late_minutes,
            'rounding_policy' => $this->rounding_policy,
            'requires_approval_by_role' => $this->requires_approval_by_role,
        ];
    }
}
