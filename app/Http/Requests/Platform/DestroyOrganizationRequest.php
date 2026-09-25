<?php

namespace App\Http\Requests\Platform;

use App\Domain\Auth\Models\Organization;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The typed-name confirmation is checked here, on the server, and again in
 * App\Domain\Auth\Actions\PurgeOrganizationAction — the dialog's disabled
 * button is a convenience, not the safeguard.
 */
class DestroyOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-organizations') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organization = $this->route('organization');

        return [
            'confirmation_name' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail) use ($organization): void {
                    if (! $organization instanceof Organization || $value !== $organization->name) {
                        $fail('დასადასტურებლად ორგანიზაციის დასახელება ზუსტად უნდა ჩაიწეროს.');
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['confirmation_name' => 'ორგანიზაციის დასახელება'];
    }
}
