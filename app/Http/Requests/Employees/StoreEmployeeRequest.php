<?php

namespace App\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * spec section 5 profile fields. `organization_id`/`status` are never
 * accepted from the client — derived server-side
 * (App\Domain\Employees\Actions\CreateEmployeeAction sets status='active'
 * directly; BelongsToOrganization stamps organization_id).
 */
class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Real Policy check (EmployeePolicy::create) runs in the controller
        // before the Action executes — this only validates shape.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'internal_code' => [
                'required', 'string', 'max:50',
                Rule::unique('employees', 'internal_code')->where('organization_id', $this->user()->organization_id),
            ],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
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
            'employment_started_at' => ['required', 'date'],
        ];
    }

    /**
     * Return the validated payload in the exact shape consumed by the
     * employee creation domain action.
     *
     * @return array{internal_code: string, first_name: string, last_name: string, phone: string|null, personal_id_number: string|null, photo_attachment_id: string|null, position: string|null, profession_skills: list<string>|null, team_id: string|null, supervisor_employee_id: string|null, emergency_contact_name: string|null, emergency_contact_phone: string|null, employment_started_at: string}
     */
    public function employeeData(): array
    {
        $skills = $this->validated('profession_skills');

        return [
            'internal_code' => (string) $this->validated('internal_code'),
            'first_name' => (string) $this->validated('first_name'),
            'last_name' => (string) $this->validated('last_name'),
            'phone' => $this->validatedNullableString('phone'),
            'personal_id_number' => $this->validatedNullableString('personal_id_number'),
            'photo_attachment_id' => $this->validatedNullableString('photo_attachment_id'),
            'position' => $this->validatedNullableString('position'),
            'profession_skills' => is_array($skills)
                ? array_values(array_filter($skills, is_string(...)))
                : null,
            'team_id' => $this->validatedNullableString('team_id'),
            'supervisor_employee_id' => $this->validatedNullableString('supervisor_employee_id'),
            'emergency_contact_name' => $this->validatedNullableString('emergency_contact_name'),
            'emergency_contact_phone' => $this->validatedNullableString('emergency_contact_phone'),
            'employment_started_at' => (string) $this->validated('employment_started_at'),
        ];
    }

    private function validatedNullableString(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) ? $value : null;
    }
}
