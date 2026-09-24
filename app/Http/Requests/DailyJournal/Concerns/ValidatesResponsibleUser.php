<?php

namespace App\Http\Requests\DailyJournal\Concerns;

use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Audit A12: `responsible_user_id` used to be validated as nothing more
 * than a well-formed uuid, so any uuid — including a user of another
 * organization — was accepted. The selector that now feeds this field is a
 * convenience, not a boundary, so the rule below is where the real
 * constraint lives. Store and Update share it deliberately: two copies of a
 * tenant-scoping rule is exactly how one of them ends up weaker.
 */
trait ValidatesResponsibleUser
{
    protected function responsibleUserRule(): Exists
    {
        // The boolean goes through a closure, not `->where('col', false)`:
        // DatabaseRule stringifies its where-values, so a raw `false` becomes
        // an empty string and the constraint silently matches nothing.
        return Rule::exists('users', 'id')
            ->where('organization_id', $this->user()?->organization_id)
            ->where(fn (Builder $query) => $query->where('is_system_account', false));
    }
}
