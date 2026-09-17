<?php

namespace App\Http\Requests\Projects;

use App\Domain\Projects\Models\Client;
use Illuminate\Foundation\Http\FormRequest;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Client::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'contact_info' => ['nullable', 'array'],
            'contact_info.phone' => ['nullable', 'string', 'max:50'],
            'contact_info.email' => ['nullable', 'email', 'max:255'],
            'contact_info.contact_person' => ['nullable', 'string', 'max:255'],
        ];
    }
}
