<?php

namespace App\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class StoreEmployeePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:20480'],
        ];
    }

    public function uploadedPhoto(): UploadedFile
    {
        $file = $this->file('photo');

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['photo' => ['ფოტო სავალდებულოა.']]);
        }

        return $file;
    }
}
