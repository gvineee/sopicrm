<?php

namespace App\Http\Requests\Timesheets;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTimesheetEmailBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller runs the real `sendBatch` class-level Policy check,
        // and CreateTimesheetEmailBatchAction re-checks `send` per selected
        // timesheet — this FormRequest only validates shape.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Explicit ids (page/manual selection) OR select_all_filtered
            // (the "select all N filtered" affordance) — exactly one
            // dataset-selection mode is used per request; the server
            // resolves the concrete set at commit time either way, never
            // trusting a client-held id list to still be accurate.
            'timesheet_ids' => ['required_without:select_all_filtered', 'array', 'min:1'],
            'timesheet_ids.*' => ['uuid'],
            'select_all_filtered' => ['sometimes', 'boolean'],
            'filters.employee_id' => ['nullable', 'uuid'],
            'filters.status' => ['nullable', 'string'],
            'mode' => ['required', Rule::in(['per_employee', 'bundled'])],
            'bundled_recipient_email' => ['required_if:mode,bundled', 'nullable', 'email', 'max:255'],
            'bundled_recipient_user_id' => ['nullable', 'uuid'],
        ];
    }
}
