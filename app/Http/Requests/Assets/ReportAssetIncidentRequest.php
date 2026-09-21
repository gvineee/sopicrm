<?php

namespace App\Http\Requests\Assets;

use Illuminate\Foundation\Http\FormRequest;

class ReportAssetIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'incident_type' => ['required', 'in:damage,loss,write_off_request'],
            'occurred_at' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'photo_attachment_ids' => ['array'],
            'photo_attachment_ids.*' => ['uuid'],
            'estimated_repair_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array{incident_type: string, occurred_at?: ?string, location?: ?string, description: string, photo_attachment_ids?: ?list<string>, estimated_repair_cost?: ?string}
     */
    public function incidentData(): array
    {
        $photoIds = $this->validated('photo_attachment_ids');

        return [
            'incident_type' => (string) $this->validated('incident_type'),
            'occurred_at' => $this->validatedNullableString('occurred_at'),
            'location' => $this->validatedNullableString('location'),
            'description' => (string) $this->validated('description'),
            'photo_attachment_ids' => is_array($photoIds)
                ? array_values(array_filter($photoIds, is_string(...)))
                : null,
            'estimated_repair_cost' => $this->validatedNullableString('estimated_repair_cost'),
        ];
    }

    private function validatedNullableString(string $key): ?string
    {
        $value = $this->validated($key);

        return is_scalar($value) ? (string) $value : null;
    }
}
