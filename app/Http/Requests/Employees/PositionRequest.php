<?php

namespace App\Http\Requests\Employees;

use App\Domain\Employees\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $position = $this->route('position');

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('positions', 'name')
                    ->where('organization_id', $this->user()->organization_id)
                    ->ignore($position instanceof Position ? $position->id : null),
            ],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
