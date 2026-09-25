<?php

namespace App\Http\Requests\Platform;

use App\Concerns\PasswordValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizationRequest extends FormRequest
{
    use PasswordValidationRules;

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
            'default_currency' => ['nullable', 'string', 'size:3'],
            'default_timezone' => ['nullable', 'string', 'timezone:all'],
            'owner_name' => ['required', 'string', 'max:255'],
            // `users` is unique only per (organization_id, email), but login
            // looks an account up by e-mail alone — the same address in two
            // organizations would make sign-in ambiguous. Checked across
            // every organization on purpose (users carries no RLS).
            'owner_email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')],
            'owner_password' => $this->passwordRules(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'დასახელება',
            'legal_name' => 'იურიდიული დასახელება',
            'default_currency' => 'ვალუტა',
            'default_timezone' => 'დროის სარტყელი',
            'owner_name' => 'მფლობელის სახელი',
            'owner_email' => 'მფლობელის ელფოსტა',
            'owner_password' => 'პაროლი',
        ];
    }

    /** @return array{name: string, legal_name: string|null, default_currency: string, default_timezone: string} */
    public function organizationData(): array
    {
        return [
            'name' => trim((string) $this->validated('name')),
            'legal_name' => $this->nullableString('legal_name'),
            'default_currency' => strtoupper($this->nullableString('default_currency') ?? 'GEL'),
            'default_timezone' => $this->nullableString('default_timezone') ?? 'Asia/Tbilisi',
        ];
    }

    /** @return array{name: string, email: string, password: string} */
    public function ownerData(): array
    {
        return [
            'name' => trim((string) $this->validated('owner_name')),
            'email' => (string) $this->validated('owner_email'),
            'password' => (string) $this->validated('owner_password'),
        ];
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
