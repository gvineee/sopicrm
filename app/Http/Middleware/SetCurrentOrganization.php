<?php

namespace App\Http\Middleware;

use App\Domain\Auth\Models\Organization;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/architecture.md §4: derives organization_id ONLY from the
 * authenticated session/token — never from any client-supplied header,
 * query param, or body field (hard constraint). Runs on both the `web`
 * (session) and `api` (sanctum) middleware groups.
 *
 * Implementation note on "SET LOCAL" vs plain "SET": docs/architecture.md
 * §4's literal wording is "SET LOCAL app.current_org_id = ? ... at the start
 * of each request's DB transaction." Taken completely literally that
 * doesn't work outside an explicit wrapping transaction — Postgres resets a
 * LOCAL setting the instant its (implicit, one-statement) transaction ends,
 * so it would silently stop applying after the very first query. Wrapping
 * every request in one long-lived transaction just to keep a LOCAL setting
 * alive is worse (held locks for the whole request, no room for a
 * mid-request non-DB call). This middleware instead uses a plain,
 * connection-scoped `SET` and resets it in `terminate()`. That is safe here
 * because classic PHP-FPM (this app's target — no Octane) opens a fresh DB
 * connection per request; queue workers, which DO reuse a connection across
 * jobs, must not rely on this middleware and instead
 * re-derive+re-set/re-reset their own tenant context per job (see
 * App\Domain\Shared\Services\OutboxDispatcher /
 * App\Console\Commands\RelayOutboxEvents). Recorded as a routine decision in
 * docs/decisions.md.
 */
class SetCurrentOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = $this->resolveOrganizationId($request);

        CurrentOrganization::set($organizationId);

        if ($organizationId !== null) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($organizationId);

            if (DB::connection()->getDriverName() === 'pgsql') {
                // `set_config(...)` (a regular function) is used instead of
                // the `SET name = value` utility command because the latter
                // cannot be parameterized through PDO/libpq's extended
                // query protocol — this is the standard way to pass a bound
                // value into a Postgres GUC. `is_local = false` means
                // session-scoped (survives across statements/transactions
                // within this connection), reset explicitly in terminate().
                DB::statement("select set_config('app.current_org_id', ?, false)", [$organizationId]);
            }
        }

        $response = $next($request);

        $this->reset();

        return $response;
    }

    public function terminate(): void
    {
        $this->reset();
    }

    private function reset(): void
    {
        CurrentOrganization::clear();

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("select set_config('app.current_org_id', '', false)");
        }
    }

    private function resolveOrganizationId(Request $request): ?string
    {
        // Routed through a `?object`-typed helper rather than branching on
        // `$request->user()` inline: this app genuinely authenticates more
        // than one Authenticatable class across its guards (App\Models\User
        // for the session guard, App\Domain\Auth\Models\Organization for
        // Sanctum machine tokens), but static analysis tooling that infers
        // Request::user()'s type from config/auth.php can only ever see ONE
        // model per guard's `provider` — sanctum's is intentionally `null`
        // (it resolves the tokenable dynamically, per token, not via a
        // static provider model), so that inference is simply too narrow
        // here. Widening to `?object` at this one boundary is the accurate
        // type, not a workaround.
        return $this->organizationIdForPrincipal($request->user());
    }

    private function organizationIdForPrincipal(?object $authenticatable): ?string
    {
        if ($authenticatable instanceof User) {
            return $authenticatable->current_organization_id;
        }

        if ($authenticatable instanceof Organization) {
            // Machine identity: the token IS scoped to this organization.
            return $authenticatable->getKey();
        }

        return null;
    }
}
