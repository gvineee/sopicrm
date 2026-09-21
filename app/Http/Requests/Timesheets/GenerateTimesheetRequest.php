<?php

namespace App\Http\Requests\Timesheets;

use Illuminate\Foundation\Http\FormRequest;

class GenerateTimesheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'uuid'],
            'pay_period_id' => ['required', 'uuid'],
        ];
    }
}
