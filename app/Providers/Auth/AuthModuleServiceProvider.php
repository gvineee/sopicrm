<?php

namespace App\Providers\Auth;

use App\Domain\Auth\Listeners\LogAuthenticationEvent;
use App\Domain\Auth\Models\PersonalAccessToken;
use App\Domain\Auth\Support\ActiveUserProvider;
use App\Domain\Companies\Models\Company;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Models\AuditEvent;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureIdempotencyKey;
use App\Http\Middleware\SetCurrentOrganization;
use App\Models\User;
use App\Policies\AuditEventPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\ProjectPolicy;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

/**
 * Auth/RBAC/Tenancy module's own registrations — auto-discovered by
 * App\Providers\ModuleServiceProviderAggregator (docs/architecture.md
 * §3.8). Nothing here is registered by editing bootstrap/app.php,
 * bootstrap/providers.php, or config/auth.php's guard/provider CLASS
 * bindings directly (only their config VALUES, which point at what this
 * provider registers).
 */
class AuthModuleServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Project::class => ProjectPolicy::class,
        AuditEvent::class => AuditEventPolicy::class,
        Company::class => CompanyPolicy::class,
    ];

    public function register(): void
    {
        Auth::provider('active-eloquent', function ($app, array $config) {
            return new ActiveUserProvider($app->make('hash'), $config['model']);
        });
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        $this->registerPolicies();
        $this->registerFinancialAccessGate();
        $this->registerLoginAuditListeners();
        $this->pushSharedMiddleware();
    }

    /**
     * spec section 3: "სისტემურ ადმინისტრატორს ფინანსური წვდომა
     * ავტომატურად არ მიენიჭოს." A plain permission check already enforces
     * this (system_admin is never granted `finance.access` —
     * database/seeders/modules/AuthPermissionsSeeder.php), but this Gate is
     * the single named checkpoint every financial-domain controller/Policy
     * should call, so the rule lives in exactly one place rather than being
     * re-derived ad hoc per module.
     *
     * Also enforces spec section 21's "privileged roles-ისთვის MFA": having
     * the `finance.access` permission is not enough on its own — the
     * account must have confirmed two-factor authentication (Fortify's
     * `two_factor_confirmed_at`), since financial data is exactly the kind
     * of privileged access the spec has MFA in mind for. A user who hasn't
     * set up 2FA yet is denied here (not silently granted), pointing them
     * at Settings → Two-Factor Authentication rather than treating MFA as
     * merely available-but-optional for this class of access.
     */
    private function registerFinancialAccessGate(): void
    {
        Gate::define('access-financial-data', function (Authenticatable $user) {
            return $user instanceof User
                && $user->can('finance.access')
                && $user->two_factor_confirmed_at !== null;
        });
    }

    private function registerLoginAuditListeners(): void
    {
        Event::listen(Login::class, [LogAuthenticationEvent::class, 'handleLogin']);
        Event::listen(Failed::class, [LogAuthenticationEvent::class, 'handleFailed']);
        Event::listen(Logout::class, [LogAuthenticationEvent::class, 'handleLogout']);
        Event::listen(Lockout::class, [LogAuthenticationEvent::class, 'handleLockout']);
    }

    /**
     * Injects the tenancy + request-id middleware into the existing `web`
     * and `api` groups without editing bootstrap/app.php (Foundation-owned
     * per docs/architecture.md §3.8) — the standard Laravel technique for a
     * module/package to extend a middleware group it doesn't own.
     */
    private function pushSharedMiddleware(): void
    {
        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);

        // Tenant context must exist before Laravel's SubstituteBindings
        // middleware resolves organization-scoped route models, so these
        // can't simply be appended (that runs after binding and makes every
        // /resource/{tenantModel} route fail closed with a false 404). But
        // they equally can't be prepended to the very front of the group:
        // SetCurrentOrganization reads $request->user(), which needs
        // StartSession to have already hydrated the session-backed auth
        // guard — prepending ahead of StartSession made $request->user()
        // always resolve null, so the organization id (and therefore every
        // RLS-scoped query's app.current_org_id) was silently empty on
        // every real request. These are inserted right after StartSession
        // instead, which satisfies both constraints.
        $this->insertMiddlewareAfter($kernel, 'web', StartSession::class, [
            SetCurrentOrganization::class,
            AssignRequestId::class,
        ]);

        // The `api` group carries no session middleware — Sanctum's guard
        // resolves $request->user() straight from the bearer token, with no
        // StartSession-style ordering dependency — so prepending to the
        // front (before SubstituteBindings) is safe here, unlike `web`.
        $kernel->prependMiddlewareToGroup('api', AssignRequestId::class);
        $kernel->prependMiddlewareToGroup('api', SetCurrentOrganization::class);

        $this->app->make('router')->aliasMiddleware('idempotency', EnsureIdempotencyKey::class);
    }

    /**
     * @param  list<class-string>  $middleware
     */
    private function insertMiddlewareAfter(Kernel $kernel, string $group, string $anchor, array $middleware): void
    {
        $ref = new \ReflectionProperty($kernel, 'middlewareGroups');
        $ref->setAccessible(true);
        $groups = $ref->getValue($kernel);

        $current = $groups[$group] ?? [];
        $current = array_values(array_diff($current, $middleware));
        $position = array_search($anchor, $current, true);

        $position = $position === false ? count($current) : $position + 1;

        array_splice($current, $position, 0, $middleware);
        $groups[$group] = $current;

        $ref->setValue($kernel, $groups);
    }
}
