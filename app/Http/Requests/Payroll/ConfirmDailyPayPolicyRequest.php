<?php

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmDailyPayPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'full_day_threshold_minutes' => ['required', 'integer', 'min:1'],
            'half_day_threshold_minutes' => ['required', 'integer', 'min:1', 'lt:full_day_threshold_minutes'],
            'minimum_attendance_minutes' => ['required', 'integer', 'min:0'],
            'incomplete_day_behavior' => ['required', 'string', 'max:64'],
            'max_day_units_per_work_date' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array{full_day_threshold_minutes: int, half_day_threshold_minutes: int, minimum_attendance_minutes: int, incomplete_day_behavior: string, max_day_units_per_work_date: string, notes: string|null} */
    public function policyData(): array
    {
        return [
            'full_day_threshold_minutes' => (int) $this->validated('full_day_threshold_minutes'),
            'half_day_threshold_minutes' => (int) $this->validated('half_day_threshold_minutes'),
            'minimum_attendance_minutes' => (int) $this->validated('minimum_attendance_minutes'),
            'incomplete_day_behavior' => (string) $this->validated('incomplete_day_behavior'),
            'max_day_units_per_work_date' => (string) $this->validated('max_day_units_per_work_date'),
            'notes' => $this->validated('notes') !== null ? (string) $this->validated('notes') : null,
        ];
    }
}
