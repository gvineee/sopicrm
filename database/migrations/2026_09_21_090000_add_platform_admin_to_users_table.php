<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADMIN-01 (docs/claude-platform-completion-2026-09-21.md, audit finding A1):
 * replaces the `app/Providers/AppServiceProvider.php` `Gate::before()` that
 * granted every ability to whichever user's email happened to equal
 * `admin@protect.ge`. That was not durable — changing the account's email
 * would silently drop its access, and any OTHER account that later took that
 * exact email string would silently inherit full platform access.
 *
 * This column is the new, durable source of truth: keyed by user id, never
 * by email, deliberately NOT in User::$fillable (see the #[Fillable(...)]
 * attribute on that model) so it can never be mass-assigned through an
 * ordinary profile-update form — the only way to set it is
 * App\Domain\Auth\Actions\GrantPlatformAdminAction, which itself requires
 * the acting user to already be a platform admin and writes an AuditEvent.
 *
 * Backfill: the one existing bootstrap account (admin@protect.ge, if it
 * exists in this database) is granted platform-admin here, once, as a
 * straight data migration — not as an ongoing email-based rule. Going
 * forward, granting/revoking is only ever done through the Action above.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_platform_admin')->default(false)->after('is_active');
        });

        DB::table('users')
            ->where('email', 'admin@protect.ge')
            ->update(['is_platform_admin' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_platform_admin');
        });
    }
};
