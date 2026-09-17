<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadProjectDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageDocuments', $this->route('project'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:20480'],
            'caption' => ['nullable', 'string', 'max:255'],
            'classification' => ['nullable', Rule::in(['before', 'after', 'general'])],
        ];
    }
}
