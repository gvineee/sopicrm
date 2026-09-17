<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-cutting — docs/data-model.md "document_revisions" /
 * "document_annotations" (spec section 15). Created before the Projects &
 * Tasks domain migration since `tasks.drawing_revision_id` FKs here (a task
 * binds to one specific drawing revision; uploading a newer drawing never
 * silently re-points existing tasks — spec section 10 explicit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('document_attachment_id')->constrained('attachments');
            $table->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category')->nullable();
            $table->string('revision_label');
            $table->foreignUuid('author_user_id')->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->foreignUuid('approved_by_user_id')->nullable()->constrained('users');
            $table->boolean('is_current')->default(true);
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'project_id']);
        });

        // Markup/annotations stored as a separate layer, never flattened
        // into the original file (spec section 15 explicit).
        Schema::create('document_annotations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('document_revision_id')->constrained();
            $table->foreignUuid('author_user_id')->constrained('users');
            $table->json('annotation_data');
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'document_revision_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_annotations');
        Schema::dropIfExists('document_revisions');
    }
};
