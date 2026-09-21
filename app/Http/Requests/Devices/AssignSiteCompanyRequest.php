<?php

namespace App\Http\Requests\Devices;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignSiteCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller runs the real `manage` Policy check — this
        // FormRequest only validates shape.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => [
                'required', 'uuid',
                Rule::exists('companies', 'id')->where('organization_id', $this->user()->organization_id),
            ],
        ];
    }
}
