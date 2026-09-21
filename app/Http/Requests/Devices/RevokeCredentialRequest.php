<?php

namespace App\Http\Requests\Devices;

use Illuminate\Foundation\Http\FormRequest;

/**
 * spec section 6 revocation — reason is mandatory for the audit trail
 * (App\Domain\Devices\Actions\RevokeCredentialAction).
 */
class RevokeCredentialRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
