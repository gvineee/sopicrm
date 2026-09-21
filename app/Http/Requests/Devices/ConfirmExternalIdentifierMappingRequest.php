<?php

namespace App\Http\Requests\Devices;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * BIO-02: confirms an unrecognized card belongs to a specific, existing
 * employee. Deliberately requires picking an existing employee_id — there is
 * no "type a new employee's name" field here, matching the hard rule that
 * an unknown card/user must never auto-create an Employee.
 */
class ConfirmExternalIdentifierMappingRequest extends FormRequest
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
        $organizationId = $this->user()->organization_id;

        return [
            'employee_id' => [
                'required', 'uuid',
                Rule::exists('employees', 'id')->where('organization_id', $organizationId),
            ],
            'valid_from' => ['nullable', 'date'],
            'site_ids' => ['nullable', 'array'],
            'site_ids.*' => [
                'uuid',
                Rule::exists('sites', 'id')->where('organization_id', $organizationId),
            ],
        ];
    }
}
