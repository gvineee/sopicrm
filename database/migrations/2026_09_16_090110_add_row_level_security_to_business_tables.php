<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/architecture.md §4 (DEC-016): Postgres RLS as defense-in-depth on top
 * of the Eloquent global scope. Every policy reads
 * `current_setting('app.current_org_id', true)` (the `true` "missing_ok"
 * flag makes it return NULL instead of erroring when unset, so a query made
 * outside any request/job context — e.g. an artisan tinker session with no
 * SET LOCAL — safely sees zero rows rather than throwing) and both the
 * `USING` (read/update/delete) and `WITH CHECK` (insert/update) clauses are
 * set, so a session can neither read nor write another tenant's rows.
 *
 * `FORCE ROW LEVEL SECURITY` is applied on every one of these tables. This
 * matters concretely for this schema: per DEC-026, the runtime app role
 * `oda_app` OWNS the `oda_crm` database (and therefore every table in it).
 * Postgres normally exempts a table's owner from its own RLS policies —
 * FORCE removes that exemption for any non-superuser owner (superusers are
 * never subject to RLS, FORCE or not, which is why the tenant-isolation
 * Pest suite in tests/Feature/Auth/TenantIsolationRlsTest.php must — and
 * does — connect as the real `oda_app` role, never as the `postgres`
 * superuser, per the spec's explicit "tested with a real runtime DB role"
 * requirement).
 *
 * Only these tables are covered: the ones this Auth/RBAC/Tenancy pass
 * actually creates that carry organization_id as real per-tenant business
 * data (users, memberships, project_memberships, projects, audit_events,
 * outbox_events, idempotency_records). Deliberately excluded, and recorded
 * as a decision rather than an oversight:
 *  - `organizations` — this IS the tenant root; it has no organization_id.
 *  - spatie/laravel-permission's `roles`/`permissions`/`model_has_roles`/
 *    `model_has_permissions`/`role_has_permissions` — per
 *    docs/data-model.md, the seeded roles are global templates
 *    (organization_id nullable, null for all 11 seeded rows) shared
 *    reference data, not tenant-owned business rows; a later phase that
 *    introduces genuinely tenant-specific role variants must add RLS to
 *    these tables in its own additive migration when that happens.
 *  - `users` — deliberately excluded, and not only because of the login
 *    chicken-and-egg problem (locating a row by email BEFORE any tenant
 *    context exists to `SET LOCAL app.current_org_id`): Laravel Fortify's
 *    own internal helpers call `User::find($id)`/similar directly with no
 *    tenant context and no way to route through a custom auth provider —
 *    the two-factor challenge (`TwoFactorLoginRequest::challengedUser()`),
 *    password-reset broker, and signed email-verification URLs all do this.
 *    An RLS policy here would make those flows silently see zero rows
 *    (fail closed) exactly like the Eloquent-layer global scope would (see
 *    App\Models\User's own docblock for the full explanation, and why that
 *    model deliberately carries NO `BelongsToOrganization` scope either —
 *    both layers are excluded for the identical reason). `users` still has
 *    a real `organization_id` NOT NULL + FK column and per-organization
 *    email uniqueness (`unique(organization_id, email)`); any screen that
 *    lists/manages users across an organization must filter explicitly.
 *    True multi-org login disambiguation
 *    is explicitly P4 scope (docs/decisions.md).
 *
 * Every module that migrates a NEW business table with organization_id
 * must add its own additive migration enabling+forcing RLS on it using the
 * same policy shape as this file — this migration does not (and cannot,
 * since they don't exist yet) cover tables from later phases.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = [
        'memberships',
        'project_memberships',
        'projects',
        'audit_events',
        'outbox_events',
        'idempotency_records',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            // RLS is a Postgres-only feature. The fast Pest suite runs
            // against sqlite (docs/architecture.md §3.5/§3.6) and simply
            // skips this layer, relying on the Eloquent global scope alone
            // for those tests; the dedicated RLS suite
            // (tests/Feature/Auth/TenantIsolationRlsTest.php) is the one
            // that actually proves this migration's effect, and it forces a
            // real pgsql connection itself.
            return;
        }

        foreach ($this->tables as $table) {
            DB::statement("alter table {$table} enable row level security");
            DB::statement("alter table {$table} force row level security");

            DB::statement(<<<SQL
                create policy {$table}_tenant_isolation on {$table}
                using (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
                with check (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tables as $table) {
            DB::statement("drop policy if exists {$table}_tenant_isolation on {$table}");
            DB::statement("alter table {$table} no force row level security");
            DB::statement("alter table {$table} disable row level security");
        }
    }
};
