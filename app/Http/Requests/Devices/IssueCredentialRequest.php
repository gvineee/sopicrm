<?php

namespace App\Http\Requests\Devices;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * spec section 6 card form. Preserves the operator's chosen input
 * representation (hex/decimal) — App\Domain\Devices\Services\
 * CardIdentifierNormalizer (called from the controller, never here) is what
 * actually derives bytes/length/leading zeros; this class only validates
 * shape.
 */
class IssueCredentialRequest extends FormRequest
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
            'card_type' => ['required', 'string', 'max:50'],
            'input_format' => ['required', Rule::in(['hex', 'decimal'])],
            'card_value' => ['required', 'string', 'max:64'],
            'bit_length' => ['nullable', 'integer', 'min:1', 'max:256'],
            'employee_id' => [
                'required', 'uuid',
                Rule::exists('employees', 'id')->where('organization_id', $organizationId),
            ],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'site_ids' => ['nullable', 'array'],
            'site_ids.*' => [
                'uuid',
                Rule::exists('sites', 'id')->where('organization_id', $organizationId),
            ],
        ];
    }
}
