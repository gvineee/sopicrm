<?php

namespace App\Http\Requests\Projects;

use App\Domain\Shared\Services\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Audit A24. The controller runs the real Policy check; this validates shape.
 *
 * The name is unique within the organization because two clients with the
 * same name are indistinguishable in the project form's dropdown, which is
 * the only place a person ever picks one.
 */
class StoreClientRequest extends FormRequest
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
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('clients', 'name')->where('organization_id', CurrentOrganization::id()),
            ],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'კლიენტის დასახელება სავალდებულოა.',
            'name.unique' => 'ამ დასახელებით კლიენტი უკვე არსებობს.',
            'email.email' => 'ელფოსტის ფორმატი არასწორია.',
        ];
    }

    /**
     * `contact_info` is a json column, so the separate fields the form shows
     * are gathered here rather than asking the operator to type a structure.
     * Empty values are dropped so an untouched client keeps a null instead of
     * a bag of empty strings.
     *
     * @return array<string, mixed>|null
     */
    public function contactInfo(): ?array
    {
        $contact = array_filter([
            'contact_person' => $this->validated('contact_person'),
            'phone' => $this->validated('phone'),
            'email' => $this->validated('email'),
            'note' => $this->validated('note'),
        ], fn ($value) => is_string($value) && trim($value) !== '');

        return $contact === [] ? null : $contact;
    }
}
