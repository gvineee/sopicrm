<?php

namespace App\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;

class TerminateEmploymentRequest extends FormRequest
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
            'ended_on' => ['required', 'date'],
            'end_reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
