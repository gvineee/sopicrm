<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration (docs/architecture.md §3.5 — the Assets module agent
 * found a genuine gap and adds a NEW migration rather than editing the
 * already-applied 2026_09_16_090190_create_assets_domain_tables.php).
 *
 * Gap: spec section 9.6 requires an employee self-service page where the
 * employee can "request a return" on their own custody
 * ("თანამშრომელს საკუთარ გვერდზე შეუძლია ... დაბრუნების მოთხოვნა"), but
 * `custody_transactions` had no column recording that a return was
 * requested, by whom, or when — without this, "request a return" would have
 * had nowhere real to persist to and the feature would only look done in
 * the UI, which the hard constraint explicitly forbids. This does not
 * change the custody state machine (spec's own
 * draft/awaiting_receipt/issued/partially_returned/returned statuses are
 * unchanged) — a request is informational/notification-triggering only; the
 * actual return still goes through the real Return form
 * (App\Domain\Assets\Actions\ReturnCustodyAction), which is the only thing
 * that ever mutates custody_lines/asset_active_custody. Logged in
 * docs/decisions.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custody_transactions', function (Blueprint $table) {
            $table->timestampTz('return_requested_at')->nullable()->after('expected_return_at');
            $table->foreignUuid('return_requested_by_user_id')->nullable()->after('return_requested_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('custody_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('return_requested_by_user_id');
            $table->dropColumn('return_requested_at');
        });
    }
};
