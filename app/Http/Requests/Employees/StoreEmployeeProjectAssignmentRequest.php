<?php

namespace App\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeProjectAssignmentRequest extends FormRequest
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
            'project_id' => ['required', 'uuid', 'exists:projects,id'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'assignment_type' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array{project_id: string, starts_on: string, ends_on: string|null, assignment_type: string|null} */
    public function assignmentData(): array
    {
        return [
            'project_id' => (string) $this->validated('project_id'),
            'starts_on' => (string) $this->validated('starts_on'),
            'ends_on' => $this->validatedNullableString('ends_on'),
            'assignment_type' => $this->validatedNullableString('assignment_type'),
        ];
    }

    private function validatedNullableString(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) ? $value : null;
    }
}
