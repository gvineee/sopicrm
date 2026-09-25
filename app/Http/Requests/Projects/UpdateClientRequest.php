<?php

namespace App\Http\Requests\Projects;

use App\Domain\Shared\Services\CurrentOrganization;
use Illuminate\Validation\Rule;

/**
 * Audit A24. Same shape as StoreClientRequest, except the uniqueness rule
 * has to ignore the row being edited — otherwise saving a client without
 * renaming it would fail against itself.
 */
class UpdateClientRequest extends StoreClientRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('clients', 'name')
                    ->where('organization_id', CurrentOrganization::id())
                    ->ignore($this->route('client')),
            ],
        ];
    }
}
