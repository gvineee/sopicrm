<?php

namespace App\Http\Requests\Devices;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * spec section 6: "ხელახლა გაცემა ქმნის ახალ ისტორიულ assignment-ს" — a
 * reason is required so the audit trail
 * (App\Domain\Devices\Actions\ReissueCredentialAction) is meaningful.
 */
class ReissueCredentialRequest extends FormRequest
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
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'site_ids' => ['nullable', 'array'],
            'site_ids.*' => [
                'uuid',
                Rule::exists('sites', 'id')->where('organization_id', $organizationId),
            ],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
