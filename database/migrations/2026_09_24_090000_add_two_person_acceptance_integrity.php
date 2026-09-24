<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration — 03-Construction-Task-Manager-Spec-KA.md stage 1
 * (TM-02, TM-06, TM-08, TM-09, §17). Nothing here drops or rewrites an
 * existing column: `tasks.self_close_allowed` deliberately SURVIVES as a
 * historical field (§17) even though no workflow may trust it any more, and
 * `task_acceptances` stays exactly as it is so the old rows remain readable
 * next to the ledger rows derived from them.
 *
 * 1. `task_acceptance_ledger_entries` — §8's ledger. `tasks.accepted_quantity`
 *    stops being an independently-mutated number and becomes a cache of
 *    `sum(quantity_delta)` over this table. Signed deltas make the four §8
 *    invariants expressible directly:
 *      - `acceptance`  → +accepted quantity, one row per submission.
 *      - `return`      → delta 0; a zero acceptance is a RETURN, never a
 *                        "partial acceptance of nothing" (§8 explicit).
 *      - `reversal`    → negative delta, always pointing at the entry it
 *                        reverses, with a reason. The past row is never
 *                        deleted or edited.
 *      - `rework`      → delta 0; fixing a defect in already-accepted work
 *                        is not new production (§8 defect example).
 *    `task_submission_id` is UNIQUE, which is §13.2's "the final decision on
 *    a submission is protected by a unique index": two concurrent
 *    accept/return requests on one submission can only ever leave one
 *    decision row behind, whatever the transaction interleaving. Reversal
 *    and rework rows carry a null `task_submission_id` (both Postgres and
 *    SQLite allow repeated NULLs under a unique index) and point at
 *    `reverses_entry_id` instead.
 *
 * 2. `task_submissions` snapshot columns — §4/§13.2. The eligibility
 *    snapshot is taken AT SUBMISSION TIME precisely so that reassigning the
 *    task afterwards cannot retroactively hide a conflict of interest
 *    (TM-02). It records both identities the independence rule compares —
 *    User ids AND Employee ids — because "two logins do not mean two
 *    people". `checklist_snapshot` freezes the answers a reviewer is
 *    actually judging (TM-09/EV-04), `evidence_snapshot` freezes what was
 *    offered as proof including each file's real MIME type (TM-04), and
 *    `client_submitted_at` keeps the device's claimed time strictly apart
 *    from the server's `submitted_at`, which stays the trusted one.
 *
 * 3. `tasks.legacy_acceptance_unverified` — §17. Historical `completed`
 *    tasks that were closed without two independent confirmations KEEP that
 *    status and get flagged instead. No approval is ever fabricated for
 *    them; the flag is what the UI reads to say
 *    „ისტორიული ჩანაწერი — ახალი წესით ვერიფიკაცია არ არის დადასტურებული".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_acceptance_ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            // Unique — one final decision per submission (§13.2). Null for
            // reversal/rework rows, which reference reverses_entry_id.
            $table->foreignUuid('task_submission_id')->nullable()->unique()->constrained('task_submissions');
            $table->string('entry_type');
            // Signed. Positive only for `acceptance`, negative only for
            // `reversal`, exactly 0 for `return`/`rework`.
            $table->decimal('quantity_delta', 12, 2)->default(0);
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('actor_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->uuid('reverses_entry_id')->nullable();
            // One-time provenance for rows derived from pre-ledger data by
            // the backfill migration, e.g. "task_acceptances:<uuid>" (§17).
            $table->string('source_reference')->nullable();
            // §13.2: a replayed write command carrying the key it already
            // used resolves to the decision it already made, instead of
            // making a second one.
            $table->string('idempotency_key')->nullable();
            $table->timestampTz('recorded_at');
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'task_id']);
            $table->unique('source_reference');
            $table->unique(['organization_id', 'idempotency_key']);
        });

        Schema::table('task_acceptance_ledger_entries', function (Blueprint $table) {
            $table->foreign('reverses_entry_id')->references('id')->on('task_acceptance_ledger_entries')->nullOnDelete();
        });

        Schema::table('task_submissions', function (Blueprint $table) {
            // The submitting User identity alongside the already-present
            // Employee identity — the independence check compares BOTH (§4).
            $table->foreignUuid('submitted_by_user_id')->nullable()->after('submitted_by_employee_id')->constrained('users')->nullOnDelete();
            $table->json('participant_snapshot')->nullable()->after('photo_attachment_ids');
            $table->json('checklist_snapshot')->nullable()->after('participant_snapshot');
            $table->json('evidence_snapshot')->nullable()->after('checklist_snapshot');
            $table->unsignedInteger('task_version_at_submission')->nullable()->after('evidence_snapshot');
            // Device-claimed capture time. Explicitly untrusted and stored
            // apart from `submitted_at`, which the server always sets.
            $table->timestampTz('client_submitted_at')->nullable()->after('submitted_at');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->boolean('legacy_acceptance_unverified')->default(false)->after('self_close_allowed');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('alter table task_acceptance_ledger_entries enable row level security');
            DB::statement('alter table task_acceptance_ledger_entries force row level security');

            DB::statement(<<<'SQL'
                create policy task_acceptance_ledger_entries_tenant_isolation on task_acceptance_ledger_entries
                using (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
                with check (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('drop policy if exists task_acceptance_ledger_entries_tenant_isolation on task_acceptance_ledger_entries');
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('legacy_acceptance_unverified');
        });

        Schema::table('task_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by_user_id');
            $table->dropColumn([
                'participant_snapshot', 'checklist_snapshot', 'evidence_snapshot',
                'task_version_at_submission', 'client_submitted_at',
            ]);
        });

        Schema::dropIfExists('task_acceptance_ledger_entries');
    }
};
