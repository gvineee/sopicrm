<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QUEUE-01 (deferred remainder, docs/claude-overnight-progress.md's own
 * recorded next step): a scheduled background job (attendance incremental
 * reconstruction, a device health-check) needs to attribute a real,
 * non-nullable `attendance_adjustments.requested_by_user_id` /
 * `AuditLogger` actor to something other than a real logged-in User.
 *
 * `users.is_system_account` mirrors ADMIN-01's own `is_platform_admin`
 * precedent exactly: a durable, explicitly-typed column, never an
 * email-string or naming-convention guess, deliberately NOT in
 * User::$fillable (see that model's #[Fillable(...)] attribute) so it can
 * only ever be set by App\Domain\Auth\Actions\GetOrCreateSystemActorAction,
 * never mass assignment. `App\Domain\Auth\Support\ActiveUserProvider`
 * already gates login on `is_active` — a system account is created with
 * `is_active = false`, so this alone makes it genuinely non-loginable, not
 * merely "not shown in a picker."
 *
 * `attendance_incremental_checkpoints` is the "last processed" marker the
 * new `attendance:process-incremental` command needs so it only re-runs
 * reconstruction for an employee with genuinely NEW raw events since last
 * time, not every employee on every tick — mirrors
 * `device_checkpoints`' own one-row-per-resource resume-point shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_system_account')->default(false)->after('is_platform_admin');
        });

        Schema::create('attendance_incremental_checkpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->timestampTz('last_processed_at');
            $table->timestamps();

            $table->unique(['organization_id', 'employee_id']);
        });

        $this->enableRowLevelSecurity();
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_incremental_checkpoints');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_system_account');
        });
    }

    private function enableRowLevelSecurity(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('alter table attendance_incremental_checkpoints enable row level security');
        DB::statement('alter table attendance_incremental_checkpoints force row level security');
        DB::statement(<<<'SQL'
            create policy attendance_incremental_checkpoints_tenant_isolation on attendance_incremental_checkpoints
            using (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
            with check (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
        SQL);
    }
};
