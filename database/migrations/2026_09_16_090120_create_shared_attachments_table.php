<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-cutting — docs/data-model.md "attachments": the single upload
 * lifecycle table (initiated -> uploaded -> scanning -> available /
 * quarantined / failed, spec section 20) that every other domain's photo/PDF
 * fields reference. `owner_type`/`owner_id` is a plain polymorphic pair with
 * NO real FK (an attachment's owner can be any of a dozen tables) — spec
 * section 19 explicit rule: "existence AND authorization of the owner are
 * re-checked server-side on every access", i.e. enforced in the Domain
 * layer, not by a DB constraint that can't span multiple target tables.
 *
 * `storage_path` is server-generated and never derived from
 * `original_filename` (spec section 21) — enforced by convention in the
 * upload-finalize Action this migration does not implement (out of this
 * schema-only pass's scope), not by the schema itself.
 *
 * This table is created before every domain that references it
 * (Employees/Devices/.../Assets) specifically so those later migrations can
 * declare a real FK on their own `*_attachment_id` columns instead of a bare
 * uuid column. `users.photo_attachment_id` already exists as a bare column
 * from the Auth/RBAC/Tenancy pass (see that migration's docblock, which
 * explicitly deferred the FK to "whichever module migrates attachments") —
 * the FK is added here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->uuidMorphs('owner');
            $table->string('disk')->default('private');
            $table->string('storage_path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('byte_size');
            $table->string('checksum')->nullable();
            $table->enum('status', ['initiated', 'uploaded', 'scanning', 'available', 'quarantined', 'failed'])
                ->default('initiated');
            $table->string('preview_path')->nullable();
            $table->foreignUuid('uploaded_by_user_id')->constrained('users');
            $table->string('caption')->nullable();
            $table->string('classification')->nullable();
            $table->timestamp('taken_at_client_claimed')->nullable();
            $table->timestamps();
            $table->unsignedInteger('version')->default(1);

            $table->unique('storage_path');
            $table->index(['organization_id', 'status']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('photo_attachment_id')->references('id')->on('attachments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['photo_attachment_id']);
        });

        Schema::dropIfExists('attachments');
    }
};
