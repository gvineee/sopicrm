<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared by grantOverride and revokeOverride (App\Http\Controllers\Admin\
 * UserAccessController) — neither needs a reason, unlike deny/remove-role.
 */
class PermissionOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'permission' => ['required', 'string', Rule::exists('permissions', 'name')],
        ];
    }
}
