<?php

namespace App\Http\Resources\Tasks;

use App\Domain\Tasks\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * List-row shape for the project task board/list — spec section 10.
 *
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Audit A07: the Kanban board builds every action URL as
            // /projects/{project}/tasks/{task}/…, so a card without its
            // project id cannot be started or submitted at all.
            'project_id' => $this->project_id,
            'title' => $this->title,
            'status' => $this->status,
            'priority' => $this->priority,
            'due_at' => $this->due_at?->toIso8601String(),
            'unit' => $this->unit,
            'planned_quantity' => $this->planned_quantity,
            'accepted_quantity' => $this->accepted_quantity,
            'blocked_reason' => $this->blocked_reason,
            'accountable_owner' => $this->whenLoaded('accountableOwner', fn () => $this->accountableOwner === null ? null : [
                'id' => $this->accountableOwner->id,
                'full_name' => trim("{$this->accountableOwner->first_name} {$this->accountableOwner->last_name}"),
            ]),
            'project_location_id' => $this->project_location_id,
            'work_package_id' => $this->work_package_id,
            'version' => $this->version,
        ];
    }
}
