<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * QUEUE-01: `outbox_events` is, by its own pre-existing design
 * (App\Console\Commands\RelayOutboxEvents's docblock), the one genuinely
 * cross-tenant system table in this schema — the relay must see every
 * organization's unprocessed rows, and
 * App\Jobs\Shared\ProcessOutboxEventJob must be able to locate a single row
 * by id BEFORE it can know which tenant that row even belongs to (a real
 * chicken-and-egg problem under the standard
 * `organization_id = current_setting('app.current_org_id')` policy — that
 * policy alone makes the relay/job see ZERO rows under a real restricted
 * Postgres role, since nothing sets that GUC for an inherently cross-tenant
 * system process).
 *
 * Fixed here with two narrowly-scoped PERMISSIVE policies — NOT by granting
 * `oda_app` BYPASSRLS or superuser, which the standing constraint forbids
 * and which would silently reopen every OTHER RLS-protected table to this
 * same role too:
 *  - `outbox_events_system_relay_select` (`FOR SELECT`) covers a plain
 *    read — `RelayOutboxEvents`' own scan and `ProcessOutboxEventJob::failed()`'s
 *    lookup.
 *  - `outbox_events_system_relay_lock` (`FOR UPDATE`, explicit
 *    `WITH CHECK (false)`) covers `SELECT ... FOR UPDATE` row-locking
 *    visibility specifically — empirically confirmed (not assumed) that
 *    Postgres does NOT honor a `FOR SELECT` policy for a locking read; it
 *    requires a policy applicable to `UPDATE`. The explicit
 *    `WITH CHECK (false)` means this policy can never itself authorize the
 *    row's actual UPDATE — only the existing `outbox_events_tenant_isolation`
 *    policy's own `WITH CHECK` can, i.e. `app.current_org_id` must already
 *    be set to the row's real owning tenant (see
 *    `ProcessOutboxEventJob`'s two-phase read-then-narrow pattern: the flag
 *    below is only ever set for long enough to locate/lock a row by id,
 *    then cleared and replaced with the row's real tenant before any write).
 *  - Both gated on `app.outbox_relay_active`, a distinct GUC from
 *    `app.current_org_id` that no web request/controller/user input path
 *    anywhere in this codebase ever sets — only
 *    RelayOutboxEvents/ProcessOutboxEventJob's own trusted server-side code,
 *    scoped `is_local=true` (transaction-scoped, never leaks to another
 *    statement/job on a reused connection).
 *  - Postgres combines multiple PERMISSIVE policies for the same command
 *    with OR, so this is purely additive: every existing caller's
 *    visibility is completely unchanged unless it deliberately sets this
 *    flag, and the flag alone never grants a genuine cross-tenant write.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            create policy outbox_events_system_relay_select on outbox_events
            for select
            using (current_setting('app.outbox_relay_active', true) = '1')
        SQL);

        DB::statement(<<<'SQL'
            create policy outbox_events_system_relay_lock on outbox_events
            for update
            using (current_setting('app.outbox_relay_active', true) = '1')
            with check (false)
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('drop policy if exists outbox_events_system_relay_select on outbox_events');
        DB::statement('drop policy if exists outbox_events_system_relay_lock on outbox_events');
    }
};
