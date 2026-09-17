<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal Projects-domain placeholder.
 *
 * Full `Project` (Client, ProjectLocation, WorkPackage, Task, ... —
 * docs/data-model.md "Domain: Projects & Tasks") belongs to the Projects
 * module, out of scope for this Auth/RBAC/Tenancy pass. This table exists
 * ONLY because `project_memberships` (which IS this pass's scope — spec
 * section 3: "პროექტის წევრობა და როლის უფლებები ერთად განსაზღვრავს
 * წვდომას") has a hard, real foreign key to a real `projects` table, and a
 * Policy test proving "role + project membership together decide access"
 * needs a real project row to attach a membership to.
 *
 * The Projects module agent extends this with an ADDITIVE migration (adding
 * client_id, status, dates, budget fields, etc. — per the Module
 * Contribution Convention, docs/architecture.md §3.5) rather than replacing
 * it. Documented in docs/decisions.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->string('name');
            $table->string('code')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unsignedInteger('version')->default(1);

            $table->unique(['organization_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
