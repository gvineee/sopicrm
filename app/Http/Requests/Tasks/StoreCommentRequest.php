<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;

/**
 * spec section 10: comments (text, mentions, replies).
 */
class StoreCommentRequest extends FormRequest
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
            'body' => ['required', 'string', 'max:4000'],
            'parent_comment_id' => ['nullable', 'uuid'],
            'mentions' => ['nullable', 'array'],
            'mentions.*' => ['uuid'],
        ];
    }
}
