<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectLocationRequest extends FormRequest
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
            'level_type' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'parent_location_id' => ['nullable', 'uuid'],
        ];
    }
}
