<?php

namespace App\Http\Requests\Assets;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ASSETS-01: saves an issue draft and immediately finalizes it in one
 * request (SaveIssueDraftAction then FinalizeIssueAction) — the simple
 * "issue now" path the Vue form actually offers; a resumable multi-session
 * draft (the two Actions already support one) is not exposed as a separate
 * UI step in this pass.
 */
class IssueCustodyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'receiving_employee_id' => ['nullable', 'uuid', 'required_without:receiving_warehouse_id'],
            'receiving_warehouse_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'occurred_at' => ['nullable', 'date'],
            'expected_return_at' => ['nullable', 'date'],
            'condition_at_transaction' => ['required', 'in:new,good,fair,damaged'],
            'accessories_note' => ['nullable', 'string', 'max:2000'],
            'photo_attachment_ids' => ['array'],
            'photo_attachment_ids.*' => ['uuid'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.asset_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0.01'],
        ];
    }
}
