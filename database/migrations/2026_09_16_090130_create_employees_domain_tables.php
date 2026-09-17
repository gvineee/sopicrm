<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employees domain — docs/data-model.md "Domain: Employees" (spec section
 * 5). `teams` and `employees` are mutually referential (a team has a
 * `foreman_employee_id`, an employee has a `team_id`), so `teams` is created
 * first WITHOUT that FK, `employees` is created with its own FKs (including
 * the self-referencing `supervisor_employee_id`), and the `teams` ->
 * `employees` FK is added afterwards via `Schema::table`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->string('name');
            $table->uuid('foreman_employee_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unsignedInteger('version')->default(1);
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->string('internal_code');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone')->nullable();
            $table->text('personal_id_number_encrypted')->nullable();
            $table->foreignUuid('photo_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->string('position')->nullable();
            $table->json('profession_skills')->nullable();
            $table->foreignUuid('team_id')->nullable()->constrained('teams')->nullOnDelete();
            // Self-referencing FK deferred to a Schema::table() call below
            // (same reason as the teams->employees FK further down): Laravel's
            // Postgres grammar compiles `primary`/`foreign` as separate ALTER
            // TABLE statements rather than inlining them into CREATE TABLE, so
            // a self-referencing `->constrained('employees')` declared inside
            // this same Schema::create() fails on real Postgres with "there is
            // no unique constraint matching given keys" — the referencing
            // ALTER TABLE runs before the table (and its own not-yet-existent
            // self) is fully committed as a distinct, independently
            // constraint-checkable relation. Verified against a real local
            // Postgres 17 instance while implementing this pass (this
            // migration had never successfully run before — see
            // docs/decisions.md).
            $table->uuid('supervisor_employee_id')->nullable();
            $table->enum('status', ['active', 'inactive', 'terminated'])->default('active');
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            // Nullable + unique-when-present: spec section 5 "Employee და
            // login account ცალკე ცნებებია" — an employee may have no
            // account at all, but if it does, that account maps to exactly
            // one employee within the organization.
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
            $table->unsignedInteger('version')->default(1);

            $table->unique(['organization_id', 'internal_code']);
            $table->unique(['organization_id', 'user_id']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'team_id']);
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->foreign('foreman_employee_id')->references('id')->on('employees')->nullOnDelete();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreign('supervisor_employee_id')->references('id')->on('employees')->nullOnDelete();
        });

        Schema::create('team_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('team_id')->constrained();
            $table->foreignUuid('employee_id')->constrained();
            $table->date('started_at');
            $table->date('ended_at')->nullable();
            $table->timestamps();
            $table->unsignedInteger('version')->default(1);
        });

        // Partial unique index (data-model.md hard rule): an employee is on
        // at most one team at a time. Supported by both Postgres and
        // SQLite's `where` clause on a unique index, no driver branch
        // needed (matches project_memberships_active_unique precedent).
        DB::statement(
            'create unique index team_memberships_active_unique '.
            'on team_memberships (organization_id, employee_id) '.
            'where ended_at is null'
        );

        Schema::create('employments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('employee_id')->constrained();
            $table->date('started_at');
            $table->date('ended_at')->nullable();
            $table->string('end_reason')->nullable();
            $table->enum('status', ['active', 'ended'])->default('active');
            $table->timestamps();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'employee_id', 'status']);
        });

        Schema::create('rate_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('employee_id')->constrained();
            $table->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('rate_type', ['hourly', 'daily']);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('GEL');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('change_reason')->nullable();
            $table->foreignUuid('approved_by_user_id')->constrained('users');
            $table->timestamps();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'employee_id', 'project_id']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            // data-model.md hard rule: for a given (employee, project-or-base,
            // rate_type) group, effective periods must never overlap. A NULL
            // project_id is coalesced to a fixed nil UUID so the exclusion
            // constraint's equality check treats "no project" as its own
            // group correctly (NULL <> NULL in a normal comparison, which
            // would otherwise let base rates overlap freely).
            DB::statement(<<<'SQL'
                alter table rate_histories add constraint rate_histories_no_overlap
                exclude using gist (
                    employee_id with =,
                    coalesce(project_id, '00000000-0000-0000-0000-000000000000'::uuid) with =,
                    rate_type with =,
                    daterange(effective_from, effective_to, '[]') with &&
                )
            SQL);
        }

        Schema::create('employee_project_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('employee_id')->constrained();
            $table->foreignUuid('project_id')->constrained();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('assignment_type')->nullable();
            $table->timestamps();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'employee_id']);
            $table->index(['organization_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_project_assignments');
        Schema::dropIfExists('rate_histories');
        Schema::dropIfExists('employments');
        Schema::dropIfExists('team_memberships');

        Schema::table('teams', function (Blueprint $table) {
            $table->dropForeign(['foreman_employee_id']);
        });

        Schema::dropIfExists('employees');
        Schema::dropIfExists('teams');
    }
};
