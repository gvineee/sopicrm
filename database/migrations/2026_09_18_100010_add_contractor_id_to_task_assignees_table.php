<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends `task_assignees`' existing "either an employee or a team" row
 * convention to "an employee, a team, or a contractor" — additive nullable
 * column, no change to `tasks.accountable_owner_employee_id`, which stays the
 * single required internal accountable owner. See
 * docs/decisions.md / Contractors module plan for the reasoning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_assignees', function (Blueprint $table) {
            $table->foreignUuid('contractor_id')->nullable()->after('team_id')->constrained()->nullOnDelete();
            $table->index(['organization_id', 'contractor_id']);
        });
    }

    public function down(): void
    {
        Schema::table('task_assignees', function (Blueprint $table) {
            $table->dropForeign(['contractor_id']);
            $table->dropIndex(['organization_id', 'contractor_id']);
            $table->dropColumn('contractor_id');
        });
    }
};
