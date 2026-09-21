<?php

namespace App\Http\Requests\Contractors;

use Illuminate\Foundation\Http\FormRequest;

class AssignContractorToTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'contractor_id' => ['required', 'uuid', 'exists:contractors,id'],
            'contract_id' => ['required', 'uuid', 'exists:contractor_contracts,id'],
        ];
    }
}
