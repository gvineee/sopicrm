<?php

namespace App\Http\Requests\Companies;

use App\Domain\Companies\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $company = $this->route('company');

        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'code' => [
                'nullable', 'string', 'max:64',
                Rule::unique('companies', 'code')
                    ->where('organization_id', $this->user()->current_organization_id)
                    ->ignore($company instanceof Company ? $company->id : null),
            ],
            'default_currency' => ['required', 'string', 'size:3'],
            'default_timezone' => ['required', 'string', 'timezone:all'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array{name: string, legal_name: string|null, code: string|null, default_currency: string, default_timezone: string, is_active: bool} */
    public function companyData(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'legal_name' => $this->nullableString('legal_name'),
            'code' => $this->nullableString('code'),
            'default_currency' => strtoupper((string) $this->validated('default_currency')),
            'default_timezone' => (string) $this->validated('default_timezone'),
            'is_active' => (bool) $this->validated('is_active'),
        ];
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
