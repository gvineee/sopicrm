<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class ShiftTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'site_id' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'starts_at_local' => ['required', 'date_format:H:i'],
            'ends_at_local' => ['required', 'date_format:H:i'],
            'crosses_midnight' => ['required', 'boolean'],
            'scheduled_days' => ['required', 'array', 'min:1'],
            'scheduled_days.*' => ['string', 'in:mon,tue,wed,thu,fri,sat,sun'],
            'break_policy' => ['required', 'array'],
            'break_policy.type' => ['required', 'string', 'in:fixed,scheduled'],
            'break_policy.minutes' => ['nullable', 'integer', 'min:0'],
            'break_policy.windows' => ['nullable', 'array'],
            'allowed_late_minutes' => ['required', 'integer', 'min:0'],
            'rounding_policy' => ['required', 'array'],
            'requires_approval_by_role' => ['nullable', 'string', 'max:64'],
        ];
    }

    /** @return array{site_id: string|null, name: string, starts_at_local: string, ends_at_local: string, crosses_midnight: bool, scheduled_days: array<int, string>, break_policy: array<string, mixed>, allowed_late_minutes: int, rounding_policy: array<string, mixed>, requires_approval_by_role: string|null} */
    public function shiftTemplateData(): array
    {
        return [
            'site_id' => $this->validated('site_id') !== null ? (string) $this->validated('site_id') : null,
            'name' => (string) $this->validated('name'),
            'starts_at_local' => (string) $this->validated('starts_at_local'),
            'ends_at_local' => (string) $this->validated('ends_at_local'),
            'crosses_midnight' => (bool) $this->validated('crosses_midnight'),
            'scheduled_days' => (array) $this->validated('scheduled_days'),
            'break_policy' => (array) $this->validated('break_policy'),
            'allowed_late_minutes' => (int) $this->validated('allowed_late_minutes'),
            'rounding_policy' => (array) $this->validated('rounding_policy'),
            'requires_approval_by_role' => $this->validated('requires_approval_by_role') !== null ? (string) $this->validated('requires_approval_by_role') : null,
        ];
    }
}
