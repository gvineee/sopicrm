<?php

namespace App\Http\Resources\Timesheets;

use App\Domain\Timesheets\Models\TimesheetEmailDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TimesheetEmailDelivery */
class TimesheetEmailDeliveryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'recipient_email' => $this->recipient_email,
            'subject' => $this->subject,
            'timesheet_version_at_send' => $this->timesheet_version_at_send,
            // Only ever queued/sent/failed — see the owning migration's
            // docblock for why 'delivered' is never a valid value.
            'status' => $this->status,
            'failed_reason' => $this->failed_reason,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'requested_by_name' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy?->name),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
