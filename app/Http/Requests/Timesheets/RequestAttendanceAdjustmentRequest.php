<?php

namespace App\Http\Requests\Timesheets;

use Illuminate\Foundation\Http\FormRequest;

class RequestAttendanceAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'uuid'],
            'work_date' => ['required', 'date'],
            'site_id' => ['nullable', 'uuid'],
            'corrected_clock_in_at' => ['nullable', 'date', 'required_with:corrected_clock_out_at'],
            'corrected_clock_out_at' => ['nullable', 'date', 'required_with:corrected_clock_in_at', 'after:corrected_clock_in_at'],
            'corrected_hours' => ['nullable', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:2000'],
            'evidence_attachment_id' => ['nullable', 'uuid'],
            'original_session_id' => ['nullable', 'uuid'],
        ];
    }
}
