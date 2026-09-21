<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DenyPermissionRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
