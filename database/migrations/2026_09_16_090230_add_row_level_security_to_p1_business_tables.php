<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P0+P1 schema pass — extends
 * 2026_09_16_090110_add_row_level_security_to_business_tables.php's RLS
 * coverage (docs/architecture.md §4, DEC-016) to every business table this
 * pass adds, using the identical policy shape. Also picks up `attachments`
 * and `notifications` (created by 2026_09_16_090120/090125), which carry
 * real per-tenant `organization_id` data but were not yet covered by any RLS
 * migration — a gap closed here rather than left for a later pass to
 * rediscover.
 *
 * Deliberately excluded, same reasoning as the original migration:
 * `raw_access_events` has no `updated_at`/`version` (genuinely append-only)
 * but DOES have `organization_id`, so it IS included below — RLS applies to
 * SELECT/INSERT regardless of whether UPDATE ever happens.
 * `asset_active_custody`/`device_checkpoints`/`attendance_session_break_deductions`
 * are internal bookkeeping tables but still carry real per-tenant data, so
 * they are included too. `sessions` (this pass's own migration) is excluded
 * for the identical reason `users` is excluded in the original RLS
 * migration: no `organization_id` column exists on it at all (data-model.md
 * explicit: session content already scopes via `user_id`).
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = [
        // Cross-cutting tables from an earlier pass, not yet RLS-covered.
        'attachments',
        'notifications',
        // Employees domain.
        'teams',
        'employees',
        'team_memberships',
        'employments',
        'rate_histories',
        'employee_project_assignments',
        // Devices domain.
        'sites',
        'devices',
        'device_capabilities',
        'credentials',
        'credential_assignments',
        'access_policies',
        'access_policy_assignments',
        'device_sync_commands',
        'device_checkpoints',
        // Attendance domain.
        'raw_access_events',
        'shift_templates',
        'shift_assignments',
        'attendance_sessions',
        'attendance_session_break_deductions',
        'attendance_anomalies',
        'attendance_adjustments',
        'timesheets',
        'timesheet_lines',
        // Shared/generic.
        'approvals',
        // Payroll domain.
        'pay_periods',
        'pay_runs',
        'pay_run_lines',
        'advances',
        'payments',
        'payment_allocations',
        'pay_adjustments',
        // Assets domain.
        'assets',
        'asset_kits',
        'asset_locations',
        'asset_active_custody',
        'custody_transactions',
        'custody_lines',
        'acknowledgements',
        'maintenance',
        'asset_incidents',
        'stocktakes',
        'stocktake_lines',
        // Cross-cutting documents.
        'document_revisions',
        'document_annotations',
        // Projects & Tasks domain.
        'clients',
        'project_locations',
        'work_packages',
        'tasks',
        'task_assignees',
        'task_dependencies',
        'checklist_items',
        'task_submissions',
        'task_acceptances',
        'comments',
        'daily_reports',
        'daily_report_revisions',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
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
