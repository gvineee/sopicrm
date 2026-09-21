<?php

namespace App\Http\Requests\Assets;

use Illuminate\Foundation\Http\FormRequest;

class TransferCustodyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'receiving_employee_id' => ['nullable', 'uuid', 'required_without:receiving_warehouse_id'],
            'receiving_warehouse_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'occurred_at' => ['nullable', 'date'],
            'condition_at_transaction' => ['required', 'in:new,good,fair,damaged'],
            'accessories_note' => ['nullable', 'string', 'max:2000'],
            'photo_attachment_ids' => ['array'],
            'photo_attachment_ids.*' => ['uuid'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
