<?php

namespace App\Http\Requests\DailyJournal;

use App\Http\Requests\DailyJournal\Concerns\ValidatesResponsibleUser;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDailyReportRequest extends FormRequest
{
    use ValidatesResponsibleUser;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'target_version' => ['required', 'integer', 'min:1'],
            // Required only when editing an already-accepted (closed) day —
            // enforced by the controller against the report's current
            // status, since it depends on server-known state, not just the
            // request shape.
            'reason' => ['nullable', 'string', 'max:2000'],
            'responsible_user_id' => ['sometimes', 'uuid', $this->responsibleUserRule()],
            'team_ids' => ['sometimes', 'array'],
            'team_ids.*' => ['uuid'],
            'task_ids' => ['sometimes', 'array'],
            'task_ids.*' => ['uuid'],
            'headcount_manual_override' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'headcount_variance_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'work_performed_note' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'equipment_used' => ['sometimes', 'array'],
            'equipment_used.*' => ['string', 'max:255'],
            'materials_received_note' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'delays_note' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'quality_safety_note' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'photo_attachment_ids' => ['sometimes', 'array'],
            'photo_attachment_ids.*' => ['uuid'],
            'next_day_plan' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'weather_manual' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
