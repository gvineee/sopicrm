<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration — Daily Journal module (spec section 11), discovered gap
 * in the P0+P1 schema per docs/architecture.md §3.5 ("a module-building agent
 * that discovers a genuinely missing column/table/index does not edit an
 * existing migration file — it creates a NEW, additive migration").
 *
 * Spec section 11 explicit hard rule: "ჟურნალის სამუშაოს რაოდენობა task
 * accepted quantity-სთან ბმულით გამოჩნდეს, არა განმეორებითი ფინანსური
 * დარიცხვით" (the journal's work quantity appears LINKED to the task's
 * accepted quantity BY REFERENCE, never as a second, independent financial
 * posting). `docs/data-model.md`'s `daily_reports` table only has a free-text
 * `work_performed_note` — there is no structured way to say "this journal
 * entry concerns these specific tasks" so the UI can read each task's live
 * `tasks.accepted_quantity` instead of a duplicated number. This join table
 * is exactly that reference: it stores no quantity/amount of its own at all
 * (a plain optional note only) — the actual quantity is always read live
 * from `tasks.accepted_quantity` at render time, never copied here. See
 * docs/decisions.md for the routine-decision write-up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_report_task_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->foreignUuid('daily_report_id')->constrained('daily_reports')->cascadeOnDelete();
            $table->foreignUuid('task_id')->constrained('tasks');
            // Deliberately NO quantity/amount column — the linked task's own
            // `accepted_quantity` is the single source of truth, read live,
            // never duplicated here (spec 11 explicit hard rule above).
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->unique(['daily_report_id', 'task_id']);
            $table->index(['organization_id', 'task_id']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('alter table daily_report_task_links enable row level security');
            DB::statement('alter table daily_report_task_links force row level security');

            DB::statement(<<<'SQL'
                create policy daily_report_task_links_tenant_isolation on daily_report_task_links
                using (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
                with check (organization_id = nullif(current_setting('app.current_org_id', true), '')::uuid)
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('drop policy if exists daily_report_task_links_tenant_isolation on daily_report_task_links');
            DB::statement('alter table daily_report_task_links no force row level security');
            DB::statement('alter table daily_report_task_links disable row level security');
        }

        Schema::dropIfExists('daily_report_task_links');
    }
};
