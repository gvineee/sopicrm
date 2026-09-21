<?php

namespace App\Providers;

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
