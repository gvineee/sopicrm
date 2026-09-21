<?php

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class PayPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // `before:2100` rejects a mistyped year (e.g. a native date
            // input's year segment getting an extra digit typed into it,
            // producing something like 252026) with a clear field-level
            // error instead of letting it reach CreatePayPeriodAction as an
            // absurdly-far-future date that still technically validates.
            'starts_on' => ['required', 'date', 'before:2100-01-01'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on', 'before:2100-01-01'],
        ];
    }
}
