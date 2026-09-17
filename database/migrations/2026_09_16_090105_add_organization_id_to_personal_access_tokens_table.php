<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/architecture.md §4: "organization_id ... for machine clients (the
 * authenticated Sanctum token's bound organization)". A machine identity
 * (e.g. services/device-connector) authenticates with a personal access
 * token whose `organization_id` is fixed at issue time
 * (App\Console\Commands\IssueMachineToken) and is what
 * App\Http\Middleware\SetCurrentOrganization trusts for token-authenticated
 * requests — never a client-supplied header/payload value (hard
 * constraint). Nullable because ordinary interactive users authenticate via
 * the session guard, not a personal access token, and if a user ever DOES
 * carry one (e.g. a future first-party mobile app token) its
 * organization_id is resolved from the user's current_organization_id at
 * issue time, not left null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignUuid('organization_id')->nullable()->after('tokenable_id')->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
