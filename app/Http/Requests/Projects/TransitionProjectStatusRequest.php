<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionProjectStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('changeStatus', $this->route('project'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['planning', 'active', 'on_hold', 'completed', 'cancelled'])],
            'reason' => ['nullable', 'string', 'max:1000'],
            'version' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
