<?php

namespace App\Providers;

use App\Domain\Auth\Support\PermissionDenialCache;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ADMIN-02 (docs/claude-platform-completion-2026-09-21.md):
        // documented authorization precedence, most authoritative first:
        //   1. An explicit deny-override (this Gate::before) — always wins,
        //      even over the platform-admin bypass below.
        //   2. A direct grant-override (spatie's own model_has_permissions,
        //      via User::givePermissionTo() — no code needed here, spatie's
        //      normal resolution already covers it).
        //   3. A role-derived permission (spatie's normal hasRole()
        //      resolution) — the baseline.
        //   4. ADMIN-01's platform-admin Gate::before bypass.
        // Registration ORDER controls precedence: Laravel's Gate runs
        // `beforeCallbacks` in registration order and returns the first
        // non-null result, so this callback is registered before the
        // platform-admin one specifically so an explicit deny can override
        // even a platform admin. It only ever returns `false` (deny) or
        // `null` (defer to the next check) — never `true`, since granting
        // access is not this callback's job.
        // Also see App\Models\User::hasPermissionTo() — spatie/laravel-permission
        // registers its OWN Gate::before (PermissionRegistrar::registerPermissions(),
        // triggered by the first-ever container resolution of Gate::class) that
        // calls $user->checkPermissionTo($ability) and returns `true` immediately
        // the moment a role/direct grant provides the permission — that `true`
        // short-circuits Gate::raw() before THIS callback ever runs, regardless of
        // registration order here. The User::hasPermissionTo() override is what
        // actually makes the deny-check win in that (most common) case; THIS
        // callback is the second half — it catches an ability that isn't a
        // registered permission name at all (so spatie's own before-callback has
        // nothing to grant and defers), ensuring an explicit deny still stops
        // ADMIN-01's platform-admin bypass below rather than falling through to it.
        Gate::before(function (Authenticatable $user, string $ability): ?bool {
            if (! $user instanceof User) {
                return null;
            }

            $organizationId = CurrentOrganization::id();

            if ($organizationId === null) {
                return null;
            }

            $deniedNames = PermissionDenialCache::deniedPermissionNames($organizationId, $user->id);

            return in_array($ability, $deniedNames, true) ? false : null;
        });

        // ADMIN-01 (docs/claude-platform-completion-2026-09-21.md, audit
        // finding A1): this used to check `$user->email === 'admin@protect.ge'`
        // directly — an email string is not a durable identity: changing it
        // silently dropped the account's access, and any other account that
        // later took that exact address would silently inherit full platform
        // access. `is_platform_admin` is keyed by user id instead (see
        // migration 2026_09_21_090000 and App\Domain\Auth\Actions\
        // GrantPlatformAdminAction, the only place that column is ever set).
        //
        // Never bypasses `access-financial-data` specifically — that ability
        // enforces confirmed 2FA before financial data is readable
        // (App\Providers\Auth\AuthModuleServiceProvider::registerFinancialAccessGate()),
        // and a blanket `Gate::before` returning `true` for every ability
        // would silently defeat that MFA requirement even for a platform
        // admin. A platform admin still has to complete 2FA to reach
        // financial data, same as anyone else.
        Gate::before(function (Authenticatable $user, string $ability): ?bool {
            if (! $user instanceof User || ! $user->is_platform_admin) {
                return null;
            }

            if ($ability === 'access-financial-data') {
                return null;
            }

            return true;
        });

        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // A JsonResource passed directly as an Inertia prop (e.g.
        // `Inertia::render(..., ['project' => new ProjectDetailResource($p)])`)
        // is otherwise wrapped in a `{"data": ...}` envelope by default —
        // meaningful for a public JSON:API, but this app's Vue pages read
        // resource fields directly off the prop (`project.code`, never
        // `project.data.code`). Disabling wrapping here, once, is the
        // standard Laravel recommendation for exactly this SPA/Inertia case
        // instead of every controller remembering to call ->resolve($request).
        JsonResource::withoutWrapping();

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
