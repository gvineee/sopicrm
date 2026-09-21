<?php

namespace App\Http\Requests\Timesheets;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared shape for submit/lock (need only the optimistic-concurrency
 * version); approve/reject extend this with a reason field.
 */
class TimesheetVersionActionRequest extends FormRequest
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
        ];
    }
}
