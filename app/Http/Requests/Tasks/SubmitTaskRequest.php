<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;

/**
 * spec section 10 employee "დავასრულე" flow — matches
 * App\Domain\Tasks\Actions\SubmitTaskForAcceptance's parameters exactly.
 */
class SubmitTaskRequest extends FormRequest
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
            'comment' => ['nullable', 'string', 'max:2000'],
            'submitted_quantity' => ['nullable', 'numeric', 'min:0'],
            'attachment_ids' => ['nullable', 'array'],
            'attachment_ids.*' => ['uuid'],
        ];
    }
}
