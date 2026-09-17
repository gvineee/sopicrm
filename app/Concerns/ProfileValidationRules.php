<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?string $userId = null, ?string $organizationId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId, $organizationId),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * Email uniqueness is per-organization (docs/data-model.md "users":
     * `unique(organization_id, email)`), not global — a `$organizationId`
     * is required to build a correct uniqueness check; omitting it falls
     * back to a plain (still-safe, just coarser) global-uniqueness check.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?string $userId = null, ?string $organizationId = null): array
    {
        $unique = Rule::unique(User::class)->when(
            $organizationId !== null,
            fn (Unique $rule) => $rule->where('organization_id', $organizationId),
        );

        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null ? $unique : $unique->ignore($userId),
        ];
    }
}
