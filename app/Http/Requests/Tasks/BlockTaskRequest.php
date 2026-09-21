<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * spec section 10: a blocked task must have a reason and a named owner of
 * unblocking it — both required, matching App\Domain\Tasks\Actions\BlockTask.
 */
class BlockTaskRequest extends FormRequest
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
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'blocked_owner_employee_id' => [
                'required', 'uuid',
                Rule::exists('employees', 'id')->where('organization_id', $this->user()->organization_id),
            ],
        ];
    }
}
