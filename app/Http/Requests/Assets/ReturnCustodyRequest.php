<?php

namespace App\Http\Requests\Assets;

use Illuminate\Foundation\Http\FormRequest;

class ReturnCustodyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'receiving_warehouse_id' => ['nullable', 'uuid'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.custody_line_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'lines.*.condition' => ['nullable', 'in:new,good,fair,damaged'],
        ];
    }
}
