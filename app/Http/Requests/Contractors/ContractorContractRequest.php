<?php

namespace App\Http\Requests\Contractors;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContractorContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'project_id' => ['nullable', 'uuid', 'exists:projects,id'],
            'contract_number' => ['nullable', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:4000'],
            'rate_type' => ['required', Rule::in(['lump_sum', 'unit_rate', 'hourly', 'daily'])],
            'rate_amount' => ['nullable', 'numeric', 'min:0'],
            'total_amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'unit' => ['nullable', 'string', 'max:64'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'terms' => ['nullable', 'string', 'max:8000'],
        ];
    }

    /** @return array<string, mixed> */
    public function contractData(): array
    {
        return [
            'project_id' => $this->validated('project_id') ?: null,
            'contract_number' => $this->nullableString('contract_number'),
            'title' => (string) $this->validated('title'),
            'description' => $this->nullableString('description'),
            'rate_type' => (string) $this->validated('rate_type'),
            'rate_amount' => $this->validated('rate_amount'),
            'total_amount' => $this->validated('total_amount'),
            'currency' => strtoupper((string) $this->validated('currency')),
            'unit' => $this->nullableString('unit'),
            'starts_on' => $this->validated('starts_on'),
            'ends_on' => $this->validated('ends_on') ?: null,
            'terms' => $this->nullableString('terms'),
        ];
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
