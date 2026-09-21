<?php

namespace App\Http\Requests\Assets;

use Illuminate\Foundation\Http\FormRequest;

class StartStocktakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scope_type' => ['required', 'in:site,warehouse'],
            'scope_id' => ['required', 'uuid'],
        ];
    }
}
