<?php

namespace App\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;

class StoreTeamRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'foreman_employee_id' => ['nullable', 'uuid', 'exists:employees,id'],
        ];
    }

    /** @return array{name: string, foreman_employee_id: string|null} */
    public function teamData(): array
    {
        $foremanId = $this->validated('foreman_employee_id');

        return [
            'name' => (string) $this->validated('name'),
            'foreman_employee_id' => is_string($foremanId) ? $foremanId : null,
        ];
    }
}
