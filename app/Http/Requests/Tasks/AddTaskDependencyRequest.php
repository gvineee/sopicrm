<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddTaskDependencyRequest extends FormRequest
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
            'depends_on_task_id' => [
                'required', 'uuid',
                Rule::exists('tasks', 'id')->where('organization_id', $this->user()->organization_id),
            ],
        ];
    }
}
