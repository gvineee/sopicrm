<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * spec section 10 task edit — status is deliberately never accepted here;
 * see the dedicated transition endpoints (assign/start/block/unblock/...).
 *
 * Audit A08: the create form offered additional performers, dependencies and
 * a checklist; this one accepted none of them, so anything set at creation
 * could never afterwards be corrected. The same three collections are now
 * accepted, validated exactly as StoreTaskRequest validates them.
 *
 * Each collection is `sometimes`, not `nullable`: a client that omits the key
 * leaves that collection untouched, while sending an empty array clears it.
 * That distinction matters because the edit form and a future partial update
 * must not be able to silently wipe a task's crew by saying nothing about it.
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
                // Not merely "an employee of this organization": a person
                // still awaiting verification cannot be made responsible for
                // work, because nobody has yet said which department they
                // belong to or what they may do here.
                Rule::exists('employees', 'id')
                    ->where('organization_id', $organizationId)
                    ->where(fn ($query) => $query->where('status', '!=', 'pending_verification')),
            ],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'due_at' => ['nullable', 'date'],
            'planned_duration_minutes' => ['nullable', 'integer', 'min:1'],
            // Audit A19: „მოცულობა ჩანს ფორმით `0.00 / — 20`; რედაქტირებაში
            // ერთეულია `20`, დაგეგმილი რაოდენობა ცარიელია." The two fields
            // only mean anything together: a unit with no quantity measures
            // nothing, and a quantity with no unit does not say of what. Each
            // now requires the other, so the pair cannot be half-filled.
            'unit' => ['nullable', 'string', 'max:50', 'required_with:planned_quantity'],
            'planned_quantity' => ['nullable', 'numeric', 'min:0', 'required_with:unit'],
            // TM-01: the self-close carve-out is cancelled. The column
            // survives as history (§17) but nothing in the workflow reads it,
            // so no request may set it either — accepting a value here would
            // record a decision that has no effect.
            'requires_photo_evidence' => ['nullable', 'boolean'],
            'min_required_photos' => ['nullable', 'integer', 'min:0'],

            'checklist_items' => ['sometimes', 'array'],
            // An existing row is identified by its own id so that editing a
            // label does not destroy and recreate the item, which would throw
            // away who ticked it and when. A row with no id is a new one.
            'checklist_items.*.id' => [
                'nullable', 'uuid',
                Rule::exists('checklist_items', 'id')->where('organization_id', $organizationId),
            ],
            'checklist_items.*.label' => ['required_with:checklist_items', 'string', 'max:255'],
            'checklist_items.*.is_required' => ['nullable', 'boolean'],

            'assignee_employee_ids' => ['sometimes', 'array'],
            'assignee_employee_ids.*' => [
                'uuid',
                Rule::exists('employees', 'id')->where('organization_id', $organizationId),
            ],
            'assignee_team_ids' => ['sometimes', 'array'],
            'assignee_team_ids.*' => [
                'uuid',
                Rule::exists('teams', 'id')->where('organization_id', $organizationId),
            ],
            'depends_on_task_ids' => ['sometimes', 'array'],
            'depends_on_task_ids.*' => [
                'uuid',
                Rule::exists('tasks', 'id')->where('organization_id', $organizationId),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'unit.required_with' => 'მიუთითეთ ერთეული (მაგ. მ², ცალი) — რიცხვი ერთეულის გარეშე გაუგებარია.',
            'planned_quantity.required_with' => 'მიუთითეთ დაგეგმილი მოცულობა — ერთეული რაოდენობის გარეშე არაფერს ზომავს.',
        ];
    }
}
