<?php

namespace App\Http\Requests\Devices;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AcknowledgeConnectorCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'result' => ['required', Rule::in(['succeeded', 'retry', 'failed'])],
            'error' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
