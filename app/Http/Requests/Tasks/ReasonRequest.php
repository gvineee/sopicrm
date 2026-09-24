<?php

namespace App\Http\Requests\Tasks;

use App\Http\Requests\Concerns\CarriesWriteCommandEnvelope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared shape for the several task actions that only ever need a required
 * text reason: unblock (optional note), cancel, reopen, return-submission.
 * Controllers decide per-action whether the field is required.
 */
class ReasonRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:1000'],
            ...$this->envelopeRules(),
        ];
    }
}
