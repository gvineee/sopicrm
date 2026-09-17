<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageWbs', $this->route('project'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'project_location_id' => ['nullable', 'uuid'],
        ];
    }
}
