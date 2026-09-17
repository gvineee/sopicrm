<?php

namespace App\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeRequest extends FormRequest
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
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'personal_id_number' => ['nullable', 'string', 'max:50'],
            'photo_attachment_id' => ['nullable', 'uuid'],
            'position' => ['nullable', 'string', 'max:255'],
            'profession_skills' => ['nullable', 'array'],
            'profession_skills.*' => ['string', 'max:100'],
            'team_id' => ['nullable', 'uuid', 'exists:teams,id'],
            'supervisor_employee_id' => ['nullable', 'uuid', 'exists:employees,id'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:50'],
        ];
    }
}
