<?php

namespace App\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;

/**
 * spec section 5: rate type hourly/daily, amount, currency, effective
 * period, optional project override, change reason + approver.
 * `approved_by_user_id` is never accepted from the client — the controller
 * passes the authenticated actor to
 * App\Domain\Employees\Actions\CreateRateHistoryAction, which is the only
 * source of truth for who approved a rate.
 */
class StoreRateHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rate_type' => ['required', 'in:hourly,daily'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'currency' => ['nullable', 'string', 'size:3'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'project_id' => ['nullable', 'uuid', 'exists:projects,id'],
            'change_reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array{rate_type: string, amount: float|string, currency: string|null, effective_from: string, effective_to: string|null, project_id: string|null, change_reason: string}
     */
    public function rateData(): array
    {
        $amount = $this->validated('amount');

        return [
            'rate_type' => (string) $this->validated('rate_type'),
            'amount' => is_string($amount) || is_float($amount) ? $amount : (float) $amount,
            'currency' => $this->validatedNullableString('currency'),
            'effective_from' => (string) $this->validated('effective_from'),
            'effective_to' => $this->validatedNullableString('effective_to'),
            'project_id' => $this->validatedNullableString('project_id'),
            'change_reason' => (string) $this->validated('change_reason'),
        ];
    }

    private function validatedNullableString(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) ? $value : null;
    }
}
