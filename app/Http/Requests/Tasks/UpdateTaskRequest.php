<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * spec section 10 task edit — status is deliberately never accepted here;
 * see the dedicated transition endpoints (assign/start/block/unblock/...).
 */
class UpdateTaskRequest extends FormRequest
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
        $organizationId = $this->user()->organization_id;

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'project_location_id' => ['nullable', 'uuid'],
            'work_package_id' => ['nullable', 'uuid'],
            'accountable_owner_employee_id' => [
                'required', 'uuid',
                Rule::exists('employees', 'id')->where('organization_id', $organizationId),
            ],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'due_at' => ['nullable', 'date'],
            'planned_duration_minutes' => ['nullable', 'integer', 'min:1'],
            'unit' => ['nullable', 'string', 'max:50'],
            'planned_quantity' => ['nullable', 'numeric', 'min:0'],
            'self_close_allowed' => ['nullable', 'boolean'],
            'requires_photo_evidence' => ['nullable', 'boolean'],
            'min_required_photos' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
