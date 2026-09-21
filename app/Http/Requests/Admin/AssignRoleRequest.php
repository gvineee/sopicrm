<?php

namespace App\Http\Requests\Admin;

use Database\Seeders\RbacBaseSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(RbacBaseSeeder::ROLES)],
        ];
    }
}
