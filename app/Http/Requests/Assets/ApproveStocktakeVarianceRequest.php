<?php

namespace App\Http\Requests\Assets;

use Illuminate\Foundation\Http\FormRequest;

class ApproveStocktakeVarianceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'adjustment_type' => ['required', 'in:quantity_correction,marked_lost,confirmed_found'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
