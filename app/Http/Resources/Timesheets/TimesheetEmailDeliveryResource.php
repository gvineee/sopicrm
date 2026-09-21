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
            // Raw column value: only ever queued/sent/failed — see the
            // owning migration's docblock for why 'delivered' is never a
            // valid value. `effective_status` (TIMESHEET-EMAIL-02) folds in
            // cancellation for display, since `cancelled_at` sits alongside
            // `status = 'queued'` rather than being a 4th enum value.
            'status' => $this->status,
            'effective_status' => $this->cancelled_at !== null && $this->status === 'queued' ? 'cancelled' : $this->status,
            'failed_reason' => $this->failed_reason,
            'sent_at' => $this->sent_at?->toIso8601String(),
            // Only populated when the caller eager-loaded withCount('items')
            // (batch-progress views) — omitted otherwise so a plain
            // TIMESHEET-EMAIL-01 single-delivery display never pays for an
            // extra items() query it has no use for.
            'item_count' => $this->whenCounted('items'),
            'requested_by_name' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy?->name),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
