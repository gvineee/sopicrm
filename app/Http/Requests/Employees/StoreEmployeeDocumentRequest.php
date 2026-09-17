<?php

namespace App\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * spec section 21 upload defaults (photo 20MB/PDF 50MB — configurable, never
 * hardcoded inline elsewhere) applied here for employee documents.
 */
class StoreEmployeeDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:51200'],
            'caption' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function validatedNullableString(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) ? $value : null;
    }

    public function uploadedFile(): UploadedFile
    {
        $file = $this->file('file');

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => ['ფაილი სავალდებულოა.']]);
        }

        return $file;
    }
}
