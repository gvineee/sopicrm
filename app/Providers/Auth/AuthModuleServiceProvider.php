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
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
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

        // PWA-01: bootstrap/app.php now calls `statefulApi()` (needed so
        // resources/js/lib/taskOfflineSync.ts's browser-session requests to
        // /api/v1/... are recognized at all, not just bearer-token
        // callers), which puts Sanctum's own
        // EnsureFrontendRequestsAreStateful middleware on the `api` group.
        // That middleware is what makes the SESSION-backed guard actually
        // resolvable for a stateful request — exactly the same ordering
        // hazard the `web` group fix above already documents: prepending
        // SetCurrentOrganization ahead of it made `$request->user()`
        // resolve null for every real browser-session /api/v1 request
        // (confirmed directly: a genuine 404 from RLS silently hiding
        // every row once app.current_org_id was left empty, while
        // `auth:sanctum` itself still passed since it uses the exact
        // guard the earlier stateful-session middleware sets up). Insert
        // after it when present; a machine-token-only deployment that
        // never calls `statefulApi()` won't have this middleware in the
        // group at all, so the original prepend-to-front behavior is kept
        // as the fallback for that case.
        if (in_array(EnsureFrontendRequestsAreStateful::class, $kernel->getMiddlewareGroups()['api'] ?? [], true)) {
            $this->insertMiddlewareAfter($kernel, 'api', EnsureFrontendRequestsAreStateful::class, [
                SetCurrentOrganization::class,
                AssignRequestId::class,
            ]);
        } else {
            $kernel->prependMiddlewareToGroup('api', AssignRequestId::class);
            $kernel->prependMiddlewareToGroup('api', SetCurrentOrganization::class);
        }

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

        // PWA-01: a real, confirmed bug found while building this ticket's
        // own end-to-end test — mutating `$kernel->middlewareGroups`
        // directly via reflection (this method's only job) does NOT
        // propagate to `Illuminate\Routing\Router`'s OWN separate copy of
        // the middleware groups, which is what request-time route matching
        // actually reads. The Kernel's own public `prependMiddlewareToGroup()`/
        // `appendMiddlewareToGroup()` methods each call the Kernel's
        // protected `syncMiddlewareToRouter()` afterward specifically to
        // keep the Router's copy current; this reflection-based insert
        // skipped that step entirely. This had been silently masked for
        // the `web` group this whole time by a side effect: the `api`
        // group's OWN prepend calls used the real public methods (which DO
        // sync), and syncMiddlewareToRouter() re-syncs EVERY group, not
        // just the one being modified — so `web`'s reflection-only edit
        // above got synced as an incidental side effect of `api`'s
        // separate, legitimate calls. The moment this ticket needed the
        // exact same `insertMiddlewareAfter()` helper for the `api` group
        // too (Sanctum's stateful-session ordering fix), that accidental
        // safety net disappeared for BOTH groups at once — confirmed via a
        // real browser request: SetCurrentOrganization's own log line never
        // fired at all, RLS silently returned nothing, a real employee
        // record read back as "not found." Never rely on incidental
        // side effects from unrelated code for a correctness requirement —
        // call the real sync explicitly here instead.
        $syncMethod = new \ReflectionMethod($kernel, 'syncMiddlewareToRouter');
        $syncMethod->setAccessible(true);
        $syncMethod->invoke($kernel);
    }
}
