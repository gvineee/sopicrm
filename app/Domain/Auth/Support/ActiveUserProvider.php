<?php

namespace App\Domain\Auth\Support;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * `users.is_active` (docs/data-model.md "users") must actually gate
 * authentication, not just be a flag some future UI happens to check — a
 * deactivated account (an offboarded employee, a suspended account) must be
 * unable to log in even with the correct password. The base
 * `EloquentUserProvider` has no such concept, so credential lookup here
 * additionally requires `is_active = true`; a deactivated user gets the
 * same generic "these credentials do not match" response as a wrong
 * password, never a distinct "your account is deactivated" message (that
 * would leak account existence/status to an unauthenticated caller).
 *
 * NOTE this is NOT a tenant-scoping bypass — `App\Models\User` deliberately
 * carries no tenant global scope at all (see that model's own docblock for
 * why: Fortify's two-factor/password-reset internals call `User::find()`
 * directly, with no tenant context and no way to route through a custom
 * provider, so a scope that fails closed there would break those flows).
 * This class exists purely for the is_active gate.
 *
 * Registered as the `users` auth provider driver in config/auth.php by
 * App\Providers\Auth\AuthModuleServiceProvider.
 */
class ActiveUserProvider extends EloquentUserProvider
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $user = parent::retrieveByCredentials($credentials);

        if ($user !== null && ! $user->getAttribute('is_active')) {
            return null;
        }

        return $user;
    }
}
