<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared/generic — docs/data-model.md "approvals": a single polymorphic
 * approval-record table reused by Timesheet, PayRun, TaskAcceptance,
 * CredentialAssignment changes, etc. Placed early (right after Devices, well
 * before Payroll/Assets/Projects&Tasks all reference it) since it has no FK
 * into any domain-specific table — only `approvable_type`/`approvable_id`
 * (polymorphic) and `users`.
 *
 * `target_version` is the concrete implementation of spec section 19's
 * optimistic-concurrency approval rule: "დამტკიცების შემდეგ შეცვლილი draft
 * ვერ ჩაითვლება ძველად დამტკიცებულად" — the approval Action re-checks the
 * approvable's current `version` equals `target_version` before committing,
 * else 409. Enforced in the Domain layer (a DB trigger could also check this,
 * but the approvable type varies per row so a single generic trigger isn't a
 * natural fit); this schema-only pass records the column, not the Action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->uuidMorphs('approvable');
            $table->unsignedInteger('target_version');
            $table->foreignUuid('approver_user_id')->constrained('users');
            $table->enum('decision', ['approved', 'rejected']);
            $table->text('reason')->nullable();
            $table->timestampTz('decided_at');
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'approvable_type', 'approvable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
