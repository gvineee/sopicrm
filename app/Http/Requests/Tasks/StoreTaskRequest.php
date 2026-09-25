<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * spec section 10 task form. `project_id` comes from the route
 * (`/projects/{project}/tasks`), never client input — the controller injects
 * it before calling the Domain Action.
 */
class StoreTaskRequest extends FormRequest
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
            // TM-01: the self-close carve-out is cancelled. The column
            // survives as history (§17) but nothing in the workflow reads it,
            // so no request may set it either — accepting a value here would
            // record a decision that has no effect.
            'requires_photo_evidence' => ['nullable', 'boolean'],
            'min_required_photos' => ['nullable', 'integer', 'min:0'],
            'checklist_items' => ['nullable', 'array'],
            'checklist_items.*.label' => ['required_with:checklist_items', 'string', 'max:255'],
            'checklist_items.*.is_required' => ['nullable', 'boolean'],
            'assignee_employee_ids' => ['nullable', 'array'],
            'assignee_employee_ids.*' => [
                'uuid',
                Rule::exists('employees', 'id')->where('organization_id', $organizationId),
            ],
            'assignee_team_ids' => ['nullable', 'array'],
            'assignee_team_ids.*' => [
                'uuid',
                Rule::exists('teams', 'id')->where('organization_id', $organizationId),
            ],
            'depends_on_task_ids' => ['nullable', 'array'],
            'depends_on_task_ids.*' => [
                'uuid',
                Rule::exists('tasks', 'id')->where('organization_id', $organizationId),
            ],
        ];
    }
}
