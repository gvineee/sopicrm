<?php

namespace App\Http\Requests\Timesheets;

use Illuminate\Foundation\Http\FormRequest;

class RejectTimesheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
