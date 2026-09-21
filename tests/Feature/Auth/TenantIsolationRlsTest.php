<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Events\OutboxEventReady;
use App\Jobs\Shared\ProcessOutboxEventJob;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * docs/architecture.md §4 (DEC-016) / spec sections 18/23: Postgres RLS
 * "ტესტდება რეალური runtime DB როლით" — this suite connects to a real
 * Postgres database (config/database.php `pgsql_rls_test`) as the ACTUAL
 * restricted `oda_app` role (non-superuser, table owner but subject to
 * `FORCE ROW LEVEL SECURITY`), never the migration/superuser role, and
 * proves cross-tenant rows are invisible at the SQL level itself — not just
 * asserted by reading the policy's CREATE POLICY definition.
 *
 * If this suite fails to connect (no local Postgres, wrong credentials),
 * every test below fails loudly rather than silently skipping — RLS is a
 * hard constraint, not a nice-to-have, so "couldn't verify it" must never
 * read as "passed."
 */
function rlsConnection(): Connection
{
    static $migrated = false;

    $connection = DB::connection('pgsql_rls_test');

    if (! $migrated) {
        // A dedicated, disposable test-only database (docs/architecture.md
        // §3.5) — migrate:fresh against it is safe and is NOT the
        // "no module agent runs migrate against a shared database" rule,
        // which is about the real dev DB (`oda_crm`).
        Artisan::call('migrate:fresh', ['--database' => 'pgsql_rls_test', '--force' => true]);
        $migrated = true;
    }

    return $connection;
}

function setRlsOrg(string $organizationId): void
{
    rlsConnection()->statement("select set_config('app.current_org_id', ?, false)", [$organizationId]);
}

function clearRlsOrg(): void
{
    rlsConnection()->statement("select set_config('app.current_org_id', '', false)");
}

beforeEach(function () {
    $connection = rlsConnection();

    expect($connection->getConfig('username'))->toBe('oda_app');
    expect($connection->selectOne('select current_user as u')->u)->toBe('oda_app');

    $isSuperuser = $connection->selectOne('select usesuper from pg_user where usename = current_user')->usesuper;
    expect($isSuperuser)->toBeFalse('The RLS test connection must run as a non-superuser role, or RLS is silently bypassed and this whole suite would be a false positive.');

    $this->tenantA = Organization::on('pgsql_rls_test')->create(['name' => 'Tenant A']);
    $this->tenantB = Organization::on('pgsql_rls_test')->create(['name' => 'Tenant B']);

    // `users` carries no RLS policy at all (App\Models\User's own docblock /
    // the RLS migrations' explicit exclusion), so these can be created
    // without first setting the RLS org context — needed only to satisfy
    // `projects.manager_user_id`'s NOT NULL FK (added by the P0+P1 schema
    // pass's docs/data-model.md "projects" migration, additive to this
    // Auth-owned test's original minimal Project row).
    $managerA = User::factory()->connection('pgsql_rls_test')->create([
        'organization_id' => $this->tenantA->id,
        'current_organization_id' => $this->tenantA->id,
        'email' => 'manager-a-'.Str::random(8).'@example.test',
    ]);
    $managerB = User::factory()->connection('pgsql_rls_test')->create([
        'organization_id' => $this->tenantB->id,
        'current_organization_id' => $this->tenantB->id,
        'email' => 'manager-b-'.Str::random(8).'@example.test',
    ]);

    setRlsOrg($this->tenantA->id);
    $this->projectA = Project::on('pgsql_rls_test')->create([
        'organization_id' => $this->tenantA->id,
        'name' => 'Project A',
        'code' => 'A-'.Str::random(8),
        'manager_user_id' => $managerA->id,
    ]);

    setRlsOrg($this->tenantB->id);
    $this->projectB = Project::on('pgsql_rls_test')->create([
        'organization_id' => $this->tenantB->id,
        'name' => 'Project B',
        'code' => 'B-'.Str::random(8),
        'manager_user_id' => $managerB->id,
    ]);
});

afterEach(function () {
    clearRlsOrg();
});

test('a raw SQL query for tenant B while scoped to tenant A returns zero rows', function () {
    setRlsOrg($this->tenantA->id);

    $rows = rlsConnection()->select('select * from projects where organization_id = ?', [$this->tenantB->id]);

    expect($rows)->toBeEmpty();
});

test('a raw SQL query with no organization context set (missing_ok) returns zero rows, never everything', function () {
    clearRlsOrg();

    $rows = rlsConnection()->select('select * from projects');

    expect($rows)->toBeEmpty();
});

test('a raw SQL query scoped to the correct tenant sees exactly its own row', function () {
    setRlsOrg($this->tenantA->id);

    $rows = rlsConnection()->select('select * from projects');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe($this->projectA->id);
});

test('an insert claiming another tenant while scoped to tenant A is rejected by the database itself', function () {
    setRlsOrg($this->tenantA->id);

    $attempt = fn () => rlsConnection()->statement(
        'insert into projects (id, organization_id, name, version, created_at, updated_at) values (?, ?, ?, 1, now(), now())',
        [(string) Str::uuid7(), $this->tenantB->id, 'Smuggled project']
    );

    expect($attempt)->toThrow(QueryException::class);

    setRlsOrg($this->tenantB->id);
    expect(rlsConnection()->select('select * from projects'))->toHaveCount(1);
});

test('an update cannot reassign a row to another tenant', function () {
    setRlsOrg($this->tenantA->id);

    $attempt = fn () => rlsConnection()->statement(
        'update projects set organization_id = ? where id = ?',
        [$this->tenantB->id, $this->projectA->id]
    );

    expect($attempt)->toThrow(QueryException::class);
});

test('memberships, project_memberships, audit_events, outbox_events and idempotency_records all enforce the same tenant isolation', function () {
    setRlsOrg($this->tenantA->id);

    $userA = rlsConnection()->table('users')->insertGetId([
        'id' => (string) Str::uuid7(),
        'organization_id' => $this->tenantA->id,
        'current_organization_id' => $this->tenantA->id,
        'name' => 'User A',
        'email' => 'a@example.test',
        'password' => 'x',
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], 'id');

    rlsConnection()->table('memberships')->insert([
        'id' => (string) Str::uuid7(),
        'organization_id' => $this->tenantA->id,
        'user_id' => $userA,
        'is_primary' => true,
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    rlsConnection()->table('project_memberships')->insert([
        'id' => (string) Str::uuid7(),
        'organization_id' => $this->tenantA->id,
        'project_id' => $this->projectA->id,
        'user_id' => $userA,
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    rlsConnection()->table('audit_events')->insert([
        'id' => (string) Str::uuid7(),
        'organization_id' => $this->tenantA->id,
        'action' => 'test.probe',
        'target_type' => 'organization',
        'target_id' => $this->tenantA->id,
        'created_at' => now(),
    ]);

    rlsConnection()->table('outbox_events')->insert([
        'id' => (string) Str::uuid7(),
        'organization_id' => $this->tenantA->id,
        'event_type' => 'test.probe',
        'subject_type' => 'organization',
        'subject_id' => $this->tenantA->id,
        'payload' => '{}',
        'available_at' => now(),
        'attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    rlsConnection()->table('idempotency_records')->insert([
        'id' => (string) Str::uuid7(),
        'organization_id' => $this->tenantA->id,
        'idempotency_key' => 'probe',
        'endpoint_signature' => 'probe',
        'request_hash' => str_repeat('a', 64),
        'response_status' => 200,
        'created_at' => now(),
    ]);

    // Positive control, still scoped to tenant A: every row just inserted
    // must actually be visible to its own tenant, ruling out a false
    // negative below (e.g. a typo'd table/column name silently matching
    // nothing either way).
    expect(rlsConnection()->table('memberships')->count())->toBe(1);
    expect(rlsConnection()->table('project_memberships')->count())->toBe(1);
    expect(rlsConnection()->table('audit_events')->count())->toBe(1);
    expect(rlsConnection()->table('outbox_events')->count())->toBe(1);
    expect(rlsConnection()->table('idempotency_records')->count())->toBe(1);

    setRlsOrg($this->tenantB->id);

    // `users` itself has no RLS policy (documented, deliberate — see the
    // migration's docblock), so it is NOT asserted here. Every table that
    // DOES carry a policy must hide tenant A's rows from tenant B.
    expect(rlsConnection()->table('memberships')->count())->toBe(0);
    expect(rlsConnection()->table('project_memberships')->count())->toBe(0);
    expect(rlsConnection()->table('audit_events')->count())->toBe(0);
    expect(rlsConnection()->table('outbox_events')->count())->toBe(0);
    expect(rlsConnection()->table('idempotency_records')->count())->toBe(0);
});

test('QUEUE-01: the real outbox relay and job process tenant A then tenant B correctly under a real restricted role, with no context leak and no double-processing', function () {
    $originalDefault = config('database.default');

    try {
        setRlsOrg($this->tenantA->id);
        $eventA = (string) Str::uuid7();
        rlsConnection()->table('outbox_events')->insert([
            'id' => $eventA,
            'organization_id' => $this->tenantA->id,
            'event_type' => 'test.probe',
            'subject_type' => 'organization',
            'subject_id' => $this->tenantA->id,
            'payload' => '{}',
            'available_at' => now(),
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        setRlsOrg($this->tenantB->id);
        $eventB = (string) Str::uuid7();
        rlsConnection()->table('outbox_events')->insert([
            'id' => $eventB,
            'organization_id' => $this->tenantB->id,
            'event_type' => 'test.probe',
            'subject_type' => 'organization',
            'subject_id' => $this->tenantB->id,
            'payload' => '{}',
            'available_at' => now(),
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Real production ambient state for a cron relay / queue worker
        // process: no `app.current_org_id` is ever set for it — this is
        // exactly the condition that made the pre-QUEUE-01 code see zero
        // rows, always, under a real restricted role.
        clearRlsOrg();
        config(['database.default' => 'pgsql_rls_test']);

        Event::fake([OutboxEventReady::class]);
        Queue::fake();

        Artisan::call('outbox:relay');

        // Other tests in this same shared, non-transactional real Postgres
        // database may have left their own unprocessed outbox rows behind
        // (this suite migrates the DB fresh once per run for speed, not
        // once per test) — assert this relay pass found (at least) both of
        // this test's own rows, not that it found ONLY them.
        $dispatchedIds = collect(Queue::pushed(ProcessOutboxEventJob::class))
            ->map(fn ($job) => $job->outboxEventId)
            ->all();
        expect($dispatchedIds)->toContain($eventA)->toContain($eventB);

        // Run both jobs for real, back to back, on what is effectively the
        // same long-lived worker process/connection — proves context from
        // processing tenant A does not leak into tenant B's run.
        (new ProcessOutboxEventJob($eventA))->handle();
        (new ProcessOutboxEventJob($eventB))->handle();

        Event::assertDispatched(OutboxEventReady::class, fn (OutboxEventReady $event) => $event->organizationId === $this->tenantA->id);
        Event::assertDispatched(OutboxEventReady::class, fn (OutboxEventReady $event) => $event->organizationId === $this->tenantB->id);
        Event::assertDispatchedTimes(OutboxEventReady::class, 2);

        // A crash/retry (re-delivery of the same job) must never double-process.
        (new ProcessOutboxEventJob($eventA))->handle();
        Event::assertDispatchedTimes(OutboxEventReady::class, 2);

        setRlsOrg($this->tenantA->id);
        expect(rlsConnection()->table('outbox_events')->where('id', $eventA)->value('processed_at'))->not->toBeNull();
        // Tenant A's session still can't see tenant B's row — isolation
        // held throughout, the relay flag never leaked into ordinary reads.
        expect(rlsConnection()->table('outbox_events')->where('id', $eventB)->exists())->toBeFalse();

        setRlsOrg($this->tenantB->id);
        expect(rlsConnection()->table('outbox_events')->where('id', $eventB)->value('processed_at'))->not->toBeNull();

        // Both the tenant-scoping GUC and the cross-tenant escape hatch are
        // transaction-scoped (`is_local=true`) — neither should still be
        // set on this session now that every transaction has committed.
        clearRlsOrg();
        $leakedOrgId = rlsConnection()->selectOne("select current_setting('app.current_org_id', true) as v")->v;
        $leakedRelayFlag = rlsConnection()->selectOne("select current_setting('app.outbox_relay_active', true) as v")->v;
        expect($leakedOrgId)->toBeEmpty()
            ->and($leakedRelayFlag)->toBeEmpty();
    } finally {
        config(['database.default' => $originalDefault]);
    }
});
