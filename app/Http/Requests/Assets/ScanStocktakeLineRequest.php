<?php

namespace App\Http\Requests\Assets;

use Illuminate\Foundation\Http\FormRequest;

class ScanStocktakeLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'counted_quantity' => ['required', 'numeric', 'min:0'],
            'recount' => ['nullable', 'boolean'],
        ];
    }
}
