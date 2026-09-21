<?php

namespace App\Http\Resources\Contractors;

use App\Domain\Contractors\Models\ContractorAct;
use App\Domain\Shared\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ContractorAct */
class ContractorActResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'can' => [
                'accept' => $this->status === 'pending_review' && $user?->can('accept', $this->resource),
                'return' => $this->status === 'pending_review' && $user?->can('returnAct', $this->resource),
            ],
            'contractor' => $this->whenLoaded('contractor', fn () => $this->contractor->only(['id', 'name'])),
            'contract_id' => $this->contract_id,
            'project_id' => $this->project_id,
            'task' => $this->whenLoaded('task', fn () => $this->task === null ? null : $this->task->only(['id', 'title'])),
            'submitted_by' => $this->whenLoaded('submittedBy', fn () => $this->submittedBy?->only(['id', 'name'])),
            'description' => $this->description,
            'quantity' => $this->quantity,
            // FILES-01 (deferred remainder): a real, protected preview URL
            // per attachment — omitted entirely for anything not
            // `available`, matching TaskDetailResource::attachmentShape()'s
            // exact rule. Before this, evidence metadata was exposed with
            // no way to actually open the file.
            'evidence' => Attachment::query()
                ->whereIn('id', $this->evidence_attachment_ids ?? [])
                ->get(['id', 'original_filename', 'mime_type', 'status'])
                ->map(fn (Attachment $attachment) => [
                    'id' => $attachment->id,
                    'original_filename' => $attachment->original_filename,
                    'mime_type' => $attachment->mime_type,
                    'status' => $attachment->status,
                    'url' => $attachment->status === 'available'
                        ? route('contractors.acts.attachments.show', [
                            'contractor' => $this->contractor_id,
                            'act' => $this->id,
                            'attachment' => $attachment->id,
                        ])
                        : null,
                ])
                ->values(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'status' => $this->status,
            'returned_reason' => $this->returned_reason,
            'acceptance' => $this->whenLoaded('acceptance', fn () => $this->acceptance === null ? null : [
                'accepted_quantity' => $this->acceptance->accepted_quantity,
                'accepted_amount' => $this->acceptance->accepted_amount,
                'accepted_at' => $this->acceptance->accepted_at?->toIso8601String(),
                'notes' => $this->acceptance->notes,
            ]),
        ];
    }
}
