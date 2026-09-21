<?php

namespace App\Http\Requests\Assets;

use Illuminate\Foundation\Http\FormRequest;

class DecideAssetIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:repair,write_off,no_action'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'target_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
