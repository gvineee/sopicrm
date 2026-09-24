<?php

namespace App\Http\Requests\Tasks;

use App\Http\Requests\Concerns\CarriesWriteCommandEnvelope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * spec section 10 employee "დავასრულე" flow — matches
 * App\Domain\Tasks\Actions\SubmitTaskForAcceptance's parameters exactly.
 *
 * `client_submitted_at` is the device's claimed capture time. It is recorded
 * separately from the server's own `submitted_at` and never replaces it
 * (§13.2: never trust a client-sent timestamp).
 */
class SubmitTaskRequest extends FormRequest
{
    use CarriesWriteCommandEnvelope;

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
            'comment' => ['nullable', 'string', 'max:2000'],
            'submitted_quantity' => ['nullable', 'numeric', 'min:0'],
            'attachment_ids' => ['nullable', 'array'],
            'attachment_ids.*' => ['uuid'],
            'client_submitted_at' => ['nullable', 'date'],
            ...$this->envelopeRules(),
        ];
    }
}
