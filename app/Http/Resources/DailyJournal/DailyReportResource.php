<?php

namespace App\Http\Resources\DailyJournal;

use App\Domain\DailyJournal\Models\DailyReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DailyReport
 */
class DailyReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'report_date' => $this->report_date->toDateString(),
            'status' => $this->status,
            'version' => $this->version,
            'responsible_user_id' => $this->responsible_user_id,
            'responsible_name' => $this->whenLoaded('responsible', fn () => $this->responsible?->name),
            'team_ids' => $this->teams_present ?? [],
            'headcount_from_attendance' => $this->headcount_from_attendance,
            'headcount_manual_override' => $this->headcount_manual_override,
            'headcount_variance_note' => $this->headcount_variance_note,
            'work_performed_note' => $this->work_performed_note,
            'equipment_used' => $this->equipment_used ?? [],
            'materials_received_note' => $this->materials_received_note,
            'delays_note' => $this->delays_note,
            'quality_safety_note' => $this->quality_safety_note,
            'photo_attachment_ids' => $this->photo_attachment_ids ?? [],
            'next_day_plan' => $this->next_day_plan,
            'weather_manual' => $this->weather_manual,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'submitted_by_name' => $this->whenLoaded('submittedBy', fn () => $this->submittedBy?->name),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'accepted_by_name' => $this->whenLoaded('acceptedBy', fn () => $this->acceptedBy?->name),
            // Section 11 hard rule: quantity is read LIVE from each linked
            // task's own accepted_quantity, never duplicated as a stored
            // figure on the journal itself.
            'linked_tasks' => $this->whenLoaded('taskLinks', fn () => $this->taskLinks->map(fn ($link) => [
                'task_id' => $link->task_id,
                'title' => $link->task?->title,
                'unit' => $link->task?->unit,
                'accepted_quantity' => $link->task?->accepted_quantity,
                'planned_quantity' => $link->task?->planned_quantity,
                'note' => $link->note,
            ])),
            'revision_count' => $this->whenCounted('revisions'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
