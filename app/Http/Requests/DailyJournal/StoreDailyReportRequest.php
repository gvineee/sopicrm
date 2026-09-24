<?php

namespace App\Http\Requests\DailyJournal;

use App\Http\Requests\DailyJournal\Concerns\ValidatesResponsibleUser;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Spec section 11 form fields. `organization_id`/`status`/`version` are
 * never accepted from the client (hard constraint) — they are derived
 * server-side by the Domain Action, not merely omitted here by convention.
 */
class StoreDailyReportRequest extends FormRequest
{
    use ValidatesResponsibleUser;

    public function authorize(): bool
    {
        // The controller runs the real Policy check (create, against the
        // resolved Project) before calling the Action — this FormRequest
        // only validates shape, per the hard constraint that hiding a field
        // is never itself the authorization boundary.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'report_date' => ['required', 'date'],
            'responsible_user_id' => ['required', 'uuid', $this->responsibleUserRule()],
            'team_ids' => ['array'],
            'team_ids.*' => ['uuid'],
            'task_ids' => ['array'],
            'task_ids.*' => ['uuid'],
            'headcount_manual_override' => ['nullable', 'integer', 'min:0'],
            'headcount_variance_note' => ['nullable', 'string', 'max:2000'],
            'work_performed_note' => ['nullable', 'string', 'max:10000'],
            'equipment_used' => ['array'],
            'equipment_used.*' => ['string', 'max:255'],
            'materials_received_note' => ['nullable', 'string', 'max:10000'],
            'delays_note' => ['nullable', 'string', 'max:10000'],
            'quality_safety_note' => ['nullable', 'string', 'max:10000'],
            'photo_attachment_ids' => ['array'],
            'photo_attachment_ids.*' => ['uuid'],
            'next_day_plan' => ['nullable', 'string', 'max:10000'],
            'weather_manual' => ['nullable', 'string', 'max:255'],
        ];
    }
}
