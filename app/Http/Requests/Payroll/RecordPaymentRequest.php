<?php

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'string', 'size:3'],
            'method' => ['required', 'string', 'max:64'],
            'reference' => ['nullable', 'string', 'max:255'],
            'evidence_attachment_id' => ['nullable', 'uuid'],
            'deducts_advance_id' => ['nullable', 'uuid'],
            // Client-generated once per form submission (MONEY-01) — same
            // key resent on any retry of that same submission is treated as
            // "the same payment", not a new one.
            'request_id' => ['required', 'uuid'],
        ];
    }
}
