<?php

namespace App\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Audit A10. The project itself is deliberately not editable here: moving an
 * assignment to a different project is not a correction, it is a different
 * assignment, and rewriting the project id in place would silently reattribute
 * every already-worked day in that period. Ending this one and starting
 * another is the honest way to transfer someone.
 */
class UpdateEmployeeProjectAssignmentRequest extends FormRequest
{
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
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'assignment_type' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ends_on.after_or_equal' => 'დასრულების თარიღი დაწყებაზე ადრე ვერ იქნება.',
        ];
    }

    /**
     * @return array{starts_on: string, ends_on: string|null, assignment_type: string|null}
     */
    public function assignmentData(): array
    {
        return [
            'starts_on' => (string) $this->validated('starts_on'),
            'ends_on' => is_string($this->validated('ends_on')) ? $this->validated('ends_on') : null,
            'assignment_type' => is_string($this->validated('assignment_type')) ? $this->validated('assignment_type') : null,
        ];
    }
}
