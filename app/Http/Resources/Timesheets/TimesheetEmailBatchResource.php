<?php

namespace App\Http\Resources\Timesheets;

use App\Domain\Timesheets\Models\TimesheetEmailBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TimesheetEmailBatch
 *
 * Per-status counts are computed live from the batch's own deliveries
 * (never stored redundantly — see the creating migration's docblock) each
 * time this Resource is built. Expects `deliveries` to already be loaded
 * (not necessarily every column — `status`/`cancelled_at` are enough).
 */
class TimesheetEmailBatchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $deliveries = $this->whenLoaded('deliveries', fn () => $this->deliveries, collect());

        $queued = $deliveries->filter(fn ($d) => $d->status === 'queued' && $d->cancelled_at === null)->count();
        $sent = $deliveries->where('status', 'sent')->count();
        $failed = $deliveries->where('status', 'failed')->count();
        $cancelled = $deliveries->filter(fn ($d) => $d->status === 'queued' && $d->cancelled_at !== null)->count();

        return [
            'id' => $this->id,
            'mode' => $this->mode,
            // 'cancelled' is the only value this column is ever explicitly
            // set to by app code (CancelTimesheetEmailBatchAction); a
            // still-`pending`/`processing` stored value is superseded here
            // by the live counts below, which are what the UI should
            // actually trust for "is everything resolved yet."
            'status' => $this->status,
            'bundled_recipient_email' => $this->bundled_recipient_email,
            'total_count' => $this->total_count,
            'skipped_details' => $this->skipped_details ?? [],
            'skipped_count' => count($this->skipped_details ?? []),
            'queued_count' => $queued,
            'sent_count' => $sent,
            'failed_count' => $failed,
            'cancelled_count' => $cancelled,
            'requested_by_name' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy?->name),
            'deliveries' => TimesheetEmailDeliveryResource::collection($this->whenLoaded('deliveries')),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
