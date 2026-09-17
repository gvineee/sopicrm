<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('project'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;
        $project = $this->route('project');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes', 'required', 'string', 'max:50',
                Rule::unique('projects', 'code')->where('organization_id', $organizationId)->ignore($project),
            ],
            'client_id' => [
                'sometimes', 'nullable', 'uuid',
                Rule::exists('clients', 'id')->where('organization_id', $organizationId),
            ],
            'manager_user_id' => [
                'sometimes', 'required', 'uuid',
                Rule::exists('users', 'id')->where('organization_id', $organizationId),
            ],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
            'budget_baseline' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999999.99'],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}
