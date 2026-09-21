<?php

namespace App\Http\Requests\Contractors;

use Illuminate\Foundation\Http\FormRequest;

class SubmitContractorActRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'contract_id' => ['required', 'uuid', 'exists:contractor_contracts,id'],
            'project_id' => ['required', 'uuid', 'exists:projects,id'],
            'task_id' => ['nullable', 'uuid', 'exists:tasks,id'],
            'description' => ['nullable', 'string', 'max:4000'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'attachment_ids' => ['nullable', 'array'],
            'attachment_ids.*' => ['uuid'],
        ];
    }
}
