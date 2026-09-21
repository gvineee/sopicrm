<?php

namespace App\Http\Requests\Projects;

use App\Domain\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `organization_id` is deliberately never a validated/accepted field —
 * it is always derived server-side (BelongsToOrganization, per the hard
 * constraint) — and `manager_user_id`/`client_id` are validated as
 * belonging to the ACTING user's own organization, never trusted bare IDs.
 */
class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Project::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'company_id' => [
                'nullable', 'uuid',
                Rule::exists('companies', 'id')->where('organization_id', $organizationId),
            ],
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('projects', 'code')->where('organization_id', $organizationId),
            ],
            'client_id' => [
                'nullable', 'uuid',
                Rule::exists('clients', 'id')->where('organization_id', $organizationId),
            ],
            'manager_user_id' => [
                'required', 'uuid',
                Rule::exists('users', 'id')->where('organization_id', $organizationId),
            ],
            'address' => ['nullable', 'string', 'max:500'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'status' => ['nullable', Rule::in(['planning', 'active'])],
            'budget_baseline' => ['nullable', 'numeric', 'min:0', 'max:99999999999.99'],
        ];
    }
}
