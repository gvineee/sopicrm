<?php

namespace App\Http\Requests\Timesheets;

use Illuminate\Foundation\Http\FormRequest;

class SendTimesheetEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller runs the real `send` Policy check before calling
        // the Action — this FormRequest only validates shape.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recipient_email' => ['required', 'email', 'max:255'],
            'recipient_user_id' => ['nullable', 'uuid'],
            'subject' => ['required', 'string', 'max:255'],
        ];
    }
}
