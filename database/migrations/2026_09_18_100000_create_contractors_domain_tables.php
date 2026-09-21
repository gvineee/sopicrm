<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contractors module (REQ-EXT-01, pulled forward from P3 backlog): external
 * companies/teams that perform work on our projects/tasks under a contract,
 * separate from the internal-tenant `companies` table and separate from
 * `employees`. A contractor is always an *additional* performer — never the
 * accountable owner of a task (see the additive `task_assignees.contractor_id`
 * migration alongside this one) — so no change is made to
 * `tasks.accountable_owner_employee_id` here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contractors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->char('default_currency', 3)->default('GEL');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            // Composite tenant-safe FK target for later tables, same pattern
            // as `companies.organization_id_id_unique` / `projects.company_id`.
            $table->unique(['organization_id', 'id'], 'contractors_organization_id_id_unique');
            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('contractor_contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('contractor_id')->constrained();
            // Null project_id = a framework/master agreement usable across
            // multiple projects, not tied to one at signing time.
            $table->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contract_number')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('rate_type', ['lump_sum', 'unit_rate', 'hourly', 'daily']);
            $table->decimal('rate_amount', 14, 2)->nullable();
            $table->decimal('total_amount', 14, 2)->nullable();
            $table->char('currency', 3)->default('GEL');
            $table->string('unit')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->enum('status', ['draft', 'pending_approval', 'active', 'closed'])->default('draft');
            $table->timestampTz('submitted_for_approval_at')->nullable();
            $table->foreignUuid('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignUuid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_reason')->nullable();
            $table->text('terms')->nullable();
            $table->foreignUuid('contract_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'contractor_id']);
            $table->index(['organization_id', 'project_id', 'status']);
        });

        Schema::create('contractor_project_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('contractor_id')->constrained();
            $table->foreignUuid('project_id')->constrained();
            $table->foreignUuid('contract_id')->nullable()->constrained('contractor_contracts')->nullOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->text('scope_description')->nullable();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'contractor_id']);
            $table->index(['organization_id', 'project_id']);
        });

        Schema::create('contractor_acts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('contractor_id')->constrained();
            // Required — resolves currency/rate context at billing time even
            // when the act isn't tied to one specific task.
            $table->foreignUuid('contract_id')->constrained('contractor_contracts');
            $table->foreignUuid('project_id')->constrained();
            $table->foreignUuid('task_id')->nullable()->constrained()->nullOnDelete();
            // Entered by our own staff on the contractor's behalf — no
            // contractor-facing login/portal exists (see plan Context).
            $table->foreignUuid('submitted_by_user_id')->constrained('users');
            $table->text('description')->nullable();
            $table->decimal('quantity', 12, 2)->nullable();
            $table->json('evidence_attachment_ids');
            $table->timestampTz('submitted_at');
            $table->enum('status', ['pending_review', 'accepted', 'returned'])->default('pending_review');
            $table->string('returned_reason')->nullable();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'contractor_id']);
            $table->index(['organization_id', 'project_id']);
            $table->index(['organization_id', 'task_id']);
        });

        Schema::create('contractor_act_acceptances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            // Unique: one acceptance per act — idempotent, prevents
            // double-counting toward the contract balance (mirrors
            // task_acceptances.task_submission_id).
            $table->foreignUuid('contractor_act_id')->unique()->constrained('contractor_acts');
            $table->foreignUuid('accepted_by_user_id')->constrained('users');
            $table->decimal('accepted_quantity', 12, 2)->nullable();
            // Entered explicitly by the reviewer rather than derived from
            // contract.rate_amount * quantity, so a later rate change can
            // never silently redefine an already-accepted amount.
            $table->decimal('accepted_amount', 14, 2);
            $table->timestampTz('accepted_at');
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);
        });

        Schema::create('contractor_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('contractor_id')->constrained();
            $table->foreignUuid('contract_id')->constrained('contractor_contracts');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('GEL');
            $table->date('paid_at');
            $table->string('method')->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('recorded_by_user_id')->constrained('users');
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'contract_id']);
        });

        $this->enableRowLevelSecurity();
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_payments');
        Schema::dropIfExists('contractor_act_acceptances');
        Schema::dropIfExists('contractor_acts');
        Schema::dropIfExists('contractor_project_assignments');
        Schema::dropIfExists('contractor_contracts');
        Schema::dropIfExists('contractors');
    }

    private function enableRowLevelSecurity(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'contractors',
            'contractor_contracts',
            'contractor_project_assignments',
            'contractor_acts',
            'contractor_act_acceptances',
            'contractor_payments',
        ] as $table) {
            DB::statement("alter table {$table} enable row level security");
            DB::statement("alter table {$table} force row level security");
            DB::statement(<<<SQL
                create policy {$table}_tenant_isolation on {$table}
                using (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
                with check (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
            SQL);
        }
    }
};
