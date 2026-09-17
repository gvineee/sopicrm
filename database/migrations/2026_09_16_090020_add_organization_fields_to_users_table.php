<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Access domain — docs/data-model.md "users": adds every ODA CRM-specific
 * column on top of the starter kit's bare users table, and switches the
 * email uniqueness constraint from global to per-organization
 * (`unique(organization_id, email)`), per the spec.
 *
 * `photo_attachment_id` is added as a bare uuid column with NO foreign key
 * yet: the `attachments` table belongs to a later module's migration set
 * (out of this Auth/RBAC/Tenancy pass's scope). Documented in
 * docs/decisions.md; the FK is added by whichever module migrates
 * `attachments`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('organization_id')->after('id')->constrained('organizations');
            $table->foreignUuid('current_organization_id')->after('organization_id')->constrained('organizations');
            $table->string('phone')->nullable()->after('email');
            $table->text('personal_id_number_encrypted')->nullable()->after('phone');
            $table->uuid('photo_attachment_id')->nullable()->after('personal_id_number_encrypted');
            $table->boolean('is_active')->default(true)->after('password');
            $table->text('mfa_secret_encrypted')->nullable()->after('is_active');
            $table->timestamp('last_login_at')->nullable()->after('mfa_secret_encrypted');
            $table->unsignedInteger('version')->default(1)->after('last_login_at');

            $table->unique(['organization_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'email']);
            $table->dropConstrainedForeignId('organization_id');
            $table->dropConstrainedForeignId('current_organization_id');
            $table->dropColumn([
                'phone',
                'personal_id_number_encrypted',
                'photo_attachment_id',
                'is_active',
                'mfa_secret_encrypted',
                'last_login_at',
                'version',
            ]);
            $table->unique('email');
        });
    }
};
