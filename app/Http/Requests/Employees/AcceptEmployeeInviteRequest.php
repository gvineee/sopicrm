<?php

namespace App\Http\Requests\Employees;

use App\Domain\Employees\Models\EmployeeInvite;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Public, unauthenticated endpoint (the one-time token itself is the
 * credential — spec section 5). Email uniqueness is scoped to the invite's
 * OWN organization (looked up here with the tenant scope bypassed, same
 * reasoning as App\Domain\Employees\Actions\AcceptEmployeeInviteAction),
 * never to the requester's organization, since there isn't one yet.
 */
class AcceptEmployeeInviteRequest extends FormRequest
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
        $invite = EmployeeInvite::withoutTenantScope()
            ->where('token_hash', hash('sha256', (string) $this->route('token')))
            ->first();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->where('organization_id', $invite?->organization_id),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array{name: string, email: string, password: string, phone: string|null}
     */
    public function inviteData(): array
    {
        $phone = $this->validated('phone');

        return [
            'name' => (string) $this->validated('name'),
            'email' => (string) $this->validated('email'),
            'password' => (string) $this->validated('password'),
            'phone' => is_string($phone) ? $phone : null,
        ];
    }
}
