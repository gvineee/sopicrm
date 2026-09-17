<?php

namespace App\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Issuing an invite (spec section 5) takes no body — HR just clicks
 * "send invite" for a specific Employee named in the route. Kept as its own
 * FormRequest (rather than a bare Request in the controller) for
 * consistency with the rest of this module and as the natural extension
 * point if a future field (e.g. a custom expiry) is added.
 */
class StoreEmployeeInviteRequest extends FormRequest
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
        return [];
    }
}
