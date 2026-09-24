<?php

namespace App\Http\Requests\Employees;

use App\Domain\Shared\Services\CurrentOrganization;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Audit A11. The controller runs the real Policy check (`manageInvite`
 * against this specific employee), so this class only validates shape — but
 * the tenant predicate below is not shape: `Rule::exists` runs on the query
 * builder, beneath Eloquent's global scope, so without it any uuid naming a
 * user of ANY organization would pass validation and only be caught deeper
 * in. The Action rejects it either way; failing here gives the person a
 * field-level error instead of a generic one.
 */
class LinkEmployeeUserRequest extends FormRequest
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
            'user_id' => [
                'required',
                'uuid',
                Rule::exists('users', 'id')
                    ->where('organization_id', CurrentOrganization::id())
                    // Through a closure, not `->where('col', false)`:
                    // DatabaseRule stringifies where-values, so a raw false
                    // becomes an empty string and matches nothing.
                    ->where(fn (Builder $query) => $query->where('is_system_account', false)),
            ],
        ];
    }

    /**
     * The validated id as the string the rules above guarantee it is.
     * `validated()` alone hands back `mixed`, which makes a downstream
     * `User::find()` look like it might be resolving a list of ids.
     */
    public function accountId(): string
    {
        return (string) $this->validated('user_id');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'აირჩიეთ ანგარიში.',
            'user_id.exists' => 'ანგარიში ვერ მოიძებნა ამ ორგანიზაციაში.',
        ];
    }
}
