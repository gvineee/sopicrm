<?php

namespace App\Http\Requests\Timesheets;

use Illuminate\Foundation\Http\FormRequest;

class DecideAttendanceAdjustmentRequest extends FormRequest
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
            'decision' => ['required', 'string', 'in:approved,rejected'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'owner_self_approval_exception_acknowledged' => ['sometimes', 'boolean'],
        ];
    }
}
