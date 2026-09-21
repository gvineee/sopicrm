<?php

namespace App\Http\Requests\Contractors;

use Illuminate\Foundation\Http\FormRequest;

class ContractorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'default_currency' => ['required', 'string', 'size:3'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array{name: string, legal_name: string|null, tax_id: string|null, contact_person: string|null, phone: string|null, email: string|null, default_currency: string, is_active: bool, notes: string|null} */
    public function contractorData(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'legal_name' => $this->nullableString('legal_name'),
            'tax_id' => $this->nullableString('tax_id'),
            'contact_person' => $this->nullableString('contact_person'),
            'phone' => $this->nullableString('phone'),
            'email' => $this->nullableString('email'),
            'default_currency' => strtoupper((string) $this->validated('default_currency')),
            'is_active' => (bool) $this->validated('is_active'),
            'notes' => $this->nullableString('notes'),
        ];
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
