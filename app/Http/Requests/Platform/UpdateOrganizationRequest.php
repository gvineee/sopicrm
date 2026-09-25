<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-organizations') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'დასახელება',
            'legal_name' => 'იურიდიული დასახელება',
        ];
    }

    /** @return array{name: string, legal_name: string|null} */
    public function organizationData(): array
    {
        $legalName = $this->validated('legal_name');

        return [
            'name' => trim((string) $this->validated('name')),
            'legal_name' => is_string($legalName) && trim($legalName) !== '' ? trim($legalName) : null,
        ];
    }
}
