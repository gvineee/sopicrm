<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employees module — additive migration (docs/architecture.md §3.5: P0+P1
 * tables were created once during Foundation; a module agent that finds a
 * genuinely missing table adds a NEW migration rather than editing an
 * existing one). `docs/data-model.md` did not model the HR-issued invite
 * link spec section 5 requires ("HR-მა უნდა შეძლოს მოწვევის შექმნა;
 * ერთჯერადი ბმული ვადიანი იყოს") — this is that gap, logged in
 * docs/decisions.md.
 *
 * Only a SHA-256 hash of the invite token is stored, never the raw token
 * (same posture as Laravel's own password-reset tokens) — the raw token
 * exists only in the one-time URL HR shares with the employee. Single-use is
 * enforced by the `status` transition (pending -> accepted, checked inside a
 * row-locked DB transaction in the accepting Action) plus a partial unique
 * index limiting an employee to one PENDING invite at a time; expiry is
 * `expires_at` checked at accept time, never relied on to auto-delete rows
 * (accepted/expired/revoked invites are kept for audit history, mirroring
 * the append-only posture used elsewhere in this schema for anything with
 * evidentiary value).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_invites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('employee_id')->constrained();
            $table->string('token_hash', 64);
            $table->timestampTz('expires_at');
            $table->enum('status', ['pending', 'accepted', 'revoked', 'expired'])->default('pending');
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignUuid('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'employee_id']);
            $table->index(['organization_id', 'token_hash']);
        });

        // At most one PENDING invite per employee at a time — issuing a new
        // one is expected to first revoke any existing pending invite (see
        // App\Domain\Employees\Actions\IssueEmployeeInviteAction), and this
        // is the DB-level backstop against a race between two concurrent
        // "resend invite" clicks.
        DB::statement(
            'create unique index employee_invites_pending_unique '.
            'on employee_invites (organization_id, employee_id) '.
            "where status = 'pending'"
        );

        if (DB::connection()->getDriverName() === 'pgsql') {
            // Same RLS policy shape as
            // 2026_09_16_090110_add_row_level_security_to_business_tables.php
            // / 2026_09_16_090230_...— see that migration's docblock for the
            // full rationale (FORCE is needed because the runtime role owns
            // the table). Skipped on sqlite (Pest's fast local/CI DB), which
            // relies on the Eloquent-layer BelongsToOrganization global scope
            // alone, per docs/architecture.md §3.5/§3.6.
            DB::statement('alter table employee_invites enable row level security');
            DB::statement('alter table employee_invites force row level security');

            DB::statement(<<<'SQL'
                create policy employee_invites_tenant_isolation on employee_invites
                using (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
                with check (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('drop policy if exists employee_invites_tenant_isolation on employee_invites');
            DB::statement('alter table employee_invites no force row level security');
            DB::statement('alter table employee_invites disable row level security');
        }

        Schema::dropIfExists('employee_invites');
    }
};
