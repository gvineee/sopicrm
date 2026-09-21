<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class ShiftAssignmentRequest extends FormRequest
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
            'shift_template_id' => ['required', 'uuid'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }

    /** @return array{employee_id: string, shift_template_id: string, effective_from: string, effective_to: string|null} */
    public function assignmentData(): array
    {
        return [
            'employee_id' => (string) $this->validated('employee_id'),
            'shift_template_id' => (string) $this->validated('shift_template_id'),
            'effective_from' => (string) $this->validated('effective_from'),
            'effective_to' => $this->validated('effective_to') !== null ? (string) $this->validated('effective_to') : null,
        ];
    }
}
