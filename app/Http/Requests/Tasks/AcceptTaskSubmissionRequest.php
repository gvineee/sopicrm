<?php

namespace App\Http\Requests\Tasks;

use App\Http\Requests\Concerns\CarriesWriteCommandEnvelope;
use Illuminate\Foundation\Http\FormRequest;

class AcceptTaskSubmissionRequest extends FormRequest
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
            // `min:0` stays only so a zero reaches the Action, which refuses
            // it with the reason that matters: a zero acceptance is a RETURN
            // and has to be recorded as one (§8).
            'accepted_quantity' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            ...$this->envelopeRules(),
        ];
    }
}
