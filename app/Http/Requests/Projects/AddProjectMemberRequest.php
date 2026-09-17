<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddProjectMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageMemberships', $this->route('project'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => [
                'required', 'uuid',
                Rule::exists('users', 'id')->where('organization_id', $this->user()->organization_id),
            ],
            'role_context' => ['nullable', 'string', 'max:50'],
        ];
    }
}
