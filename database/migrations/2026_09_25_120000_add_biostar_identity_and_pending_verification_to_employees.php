<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration. Nothing is dropped or rewritten: the status column keeps
 * every value it already allowed and gains one, and no existing row changes.
 *
 * Two things, both asked for directly:
 *
 * 1. `biostar_user_id` — BioStar's own id for a person, as a first-class
 *    identifier on our side. A card can be lost, blocked or reissued, so
 *    matching a swipe by card alone loses the person the moment their card
 *    changes. BioStar's user id does not change, which makes it the durable
 *    anchor between the two systems. Unique per organization, because two
 *    employees claiming the same upstream person would make every imported
 *    event ambiguous.
 *
 * 2. `pending_verification` — where a person created from a BioStar sync
 *    lands. They exist, they are matched to their swipes, and they are
 *    explicitly NOT yet a working member of staff: no department, no
 *    confirmed permissions. Somebody with the authority has to look at them
 *    and say so.
 *
 *    A distinct state rather than reusing `inactive`, because the two mean
 *    different things to whoever reads the roster: `inactive` is a person the
 *    organization knows and has stood down, `pending_verification` is a person
 *    the organization has not yet vouched for at all. Conflating them would
 *    hide new arrivals inside a list of former staff.
 *
 * The status column is a varchar with a CHECK constraint on PostgreSQL (not a
 * native enum type), so widening it is a constraint swap and no table rewrite.
 * SQLite — the test database — cannot add a CHECK after the fact, so there the
 * column is left unconstrained at the database level; the application's own
 * validation is what holds on both, and PostgreSQL keeps the belt as well.
 */
return new class extends Migration
{
    private const STATUSES = ['active', 'inactive', 'terminated', 'pending_verification'];

    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('biostar_user_id')->nullable()->after('internal_code');

            // Two employees claiming the same upstream person would make every
            // imported event ambiguous about who actually badged in.
            $table->unique(['organization_id', 'biostar_user_id']);
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $allowed = implode(', ', array_map(fn (string $s) => "'{$s}'", self::STATUSES));

        DB::statement('alter table employees drop constraint if exists employees_status_check');
        DB::statement("alter table employees add constraint employees_status_check check (status::text = any (array[{$allowed}]::text[]))");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Anyone still mid-verification would violate the narrower
            // constraint, so they are stood down rather than deleted — the
            // person is real and their swipes are already attributed to them.
            DB::table('employees')->where('status', 'pending_verification')->update(['status' => 'inactive']);

            DB::statement('alter table employees drop constraint if exists employees_status_check');
            DB::statement("alter table employees add constraint employees_status_check check (status::text = any (array['active', 'inactive', 'terminated']::text[]))");
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'biostar_user_id']);
            $table->dropColumn('biostar_user_id');
        });
    }
};
