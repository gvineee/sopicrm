<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * spec section 10/21: mobile camera capture, caption, before/after tagging,
 * optional/transparent GPS. Size/count limits are enforced in the Domain
 * Action (config-driven, see config/modules/tasks.php) — this only validates
 * shape.
 */
class UploadTaskAttachmentRequest extends FormRequest
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
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf'],
            'classification' => ['nullable', Rule::in(['before', 'after', 'other'])],
            'caption' => ['nullable', 'string', 'max:255'],
            'taken_at_client_claimed' => ['nullable', 'date'],
            'gps_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'gps_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'gps_consent_given' => ['nullable', 'boolean'],
        ];
    }
}
