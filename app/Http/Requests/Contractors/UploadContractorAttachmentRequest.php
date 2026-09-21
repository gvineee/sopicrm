<?php

namespace App\Http\Requests\Contractors;

use Illuminate\Foundation\Http\FormRequest;

class UploadContractorAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf'],
            'caption' => ['nullable', 'string', 'max:255'],
        ];
    }
}
