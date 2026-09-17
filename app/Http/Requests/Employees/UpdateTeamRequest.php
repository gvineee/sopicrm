<?php

namespace App\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTeamRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'foreman_employee_id' => ['nullable', 'uuid', 'exists:employees,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array{name?: string, foreman_employee_id?: string|null, is_active?: bool} */
    public function teamData(): array
    {
        $data = [];

        if ($this->has('name')) {
            $data['name'] = (string) $this->validated('name');
        }

        if ($this->has('foreman_employee_id')) {
            $foremanId = $this->validated('foreman_employee_id');
            $data['foreman_employee_id'] = is_string($foremanId) ? $foremanId : null;
        }

        if ($this->has('is_active')) {
            $data['is_active'] = $this->boolean('is_active');
        }

        return $data;
    }
}
