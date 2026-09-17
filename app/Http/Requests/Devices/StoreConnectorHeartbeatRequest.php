<?php

namespace App\Http\Requests\Devices;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConnectorHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['online', 'offline', 'degraded', 'unknown'])],
            'connector_version' => ['required', 'string', 'max:100'],
            'firmware_version' => ['nullable', 'string', 'max:100'],
            'capabilities' => ['sometimes', 'array', 'max:100'],
        ];
    }
}
