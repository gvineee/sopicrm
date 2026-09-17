<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assets domain — docs/data-model.md "Domain: Assets" (spec section 9).
 * `assets` and `asset_locations` are mutually referential (an asset has an
 * `initial_location_id`; a location row points back at its `asset_id`), so
 * `assets` is created first WITHOUT that FK, `asset_locations` is created
 * with its own FK, and the `assets -> asset_locations` FK is added
 * afterwards — the same pattern already used for `teams`/`employees` in
 * 2026_09_16_090130_create_employees_domain_tables.php.
 *
 * Routine decision (docs/decisions.md): `Warehouse` is explicitly P2 scope
 * (docs/data-model.md "Out of scope" list), so `custody_transactions`'
 * `issuing_warehouse_id`/`receiving_warehouse_id` are plain nullable UUID
 * columns with no FK for now — there is no `warehouses` table yet to
 * reference. The P2 Warehouse migration must add the FK (or a check
 * constraint) once that table exists, without changing this column's type.
 *
 * Routine decision: `custody_transactions` also gets an `issuing_employee_id`
 * (nullable FK employees) alongside the spec's `receiving_employee_id`, so a
 * direct employee-to-employee transfer records both sides as real FKs
 * instead of only being reconstructable via `asset_locations` history — this
 * is additive to, not a substitute for, the full custody chain the spec
 * requires (data-model.md: "პირდაპირი employee-to-employee გადაცემა
 * დატოვებს სრულ custody chain-ს").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->string('name');
            $table->string('category');
            $table->enum('tracking_type', ['individual', 'kit_component', 'quantity', 'consumable']);
            $table->string('inventory_code');
            // FK added below, once asset_locations exists.
            $table->uuid('initial_location_id')->nullable();
            $table->enum('condition', ['new', 'good', 'fair', 'damaged', 'under_repair', 'written_off'])->default('new');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->date('purchased_at')->nullable();
            $table->decimal('purchase_price', 14, 2)->nullable();
            $table->char('purchase_currency', 3)->nullable();
            $table->string('supplier')->nullable();
            $table->date('warranty_until')->nullable();
            $table->foreignUuid('manual_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->json('bundle_contents')->nullable();
            $table->enum('ownership', ['owned', 'rented'])->default('owned');
            $table->timestampTz('calibration_due_at')->nullable();
            $table->timestampTz('service_due_at')->nullable();
            // Only meaningful for tracking_type IN ('quantity','consumable');
            // individually-tracked assets always represent quantity 1
            // (data-model.md explicit).
            $table->decimal('quantity_on_hand', 12, 2)->nullable();
            // Opaque QR payload — resolving it always re-runs the Policy
            // check server-side; the token itself grants no access (spec
            // section 9 explicit). Globally unique (not per-org): the
            // scanning client doesn't know the organization up front.
            $table->string('qr_token')->unique();
            $table->softDeletes();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->unique(['organization_id', 'inventory_code']);
            $table->index(['organization_id', 'tracking_type']);
        });

        Schema::create('asset_kits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('kit_asset_id')->constrained('assets');
            $table->foreignUuid('component_asset_id')->constrained('assets');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->timestampsTz();

            $table->unique(['kit_asset_id', 'component_asset_id'], 'asset_kits_unique');
        });

        Schema::create('asset_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->string('locatable_type');
            $table->uuid('locatable_id');
            $table->foreignUuid('asset_id')->constrained('assets');
            $table->timestampTz('as_of');
            $table->boolean('is_current')->default(true);
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'locatable_type', 'locatable_id']);
        });

        DB::statement(
            'create unique index asset_locations_current_unique '.
            'on asset_locations (organization_id, asset_id) '.
            'where is_current = true'
        );

        Schema::table('assets', function (Blueprint $table) {
            $table->foreign('initial_location_id')->references('id')->on('asset_locations')->nullOnDelete();
        });

        // Lightweight marker table (data-model.md's own suggested design)
        // used to enforce "only one of two simultaneous issues succeeds": the
        // issue Action does `SELECT ... FOR UPDATE` on this row inside a DB
        // transaction before creating custody_transaction/custody_lines, and
        // the partial unique index below is the DB-level backstop even if
        // the row lock is somehow bypassed.
        Schema::create('asset_active_custody', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('asset_id')->constrained('assets');
            $table->enum('status', ['available', 'awaiting_receipt', 'issued', 'in_transit'])->default('available');
            // FK added below, once custody_transactions exists.
            $table->uuid('current_custody_transaction_id')->nullable();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->unique(['organization_id', 'asset_id']);
        });

        DB::statement(
            'create unique index asset_active_custody_locked_unique '.
            'on asset_active_custody (organization_id, asset_id) '.
            "where status in ('issued', 'awaiting_receipt')"
        );

        Schema::create('custody_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->enum('type', ['issue', 'return', 'transfer']);
            $table->uuid('issuing_warehouse_id')->nullable();
            $table->foreignUuid('issuing_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignUuid('receiving_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->uuid('receiving_warehouse_id')->nullable();
            $table->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('occurred_at');
            $table->timestampTz('expected_return_at')->nullable();
            $table->enum('condition_at_transaction', ['new', 'good', 'fair', 'damaged']);
            $table->string('accessories_note')->nullable();
            $table->json('photo_attachment_ids')->nullable();
            $table->text('comment')->nullable();
            $table->foreignUuid('issued_by_user_id')->constrained('users');
            $table->foreignUuid('received_confirmation_user_id')->nullable()->constrained('users');
            $table->timestampTz('received_confirmation_at')->nullable();
            // NOT a qualified electronic signature (data-model.md explicit —
            // UI copy must never claim otherwise); only account_id + this
            // timestamp are the confirmation evidence.
            $table->enum('status', [
                'draft', 'awaiting_receipt', 'issued', 'partially_returned', 'returned', 'in_transit',
            ])->default('draft');
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'receiving_employee_id']);
            $table->index(['organization_id', 'status']);
        });

        Schema::table('asset_active_custody', function (Blueprint $table) {
            $table->foreign('current_custody_transaction_id')
                ->references('id')->on('custody_transactions')->nullOnDelete();
        });

        Schema::create('custody_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('custody_transaction_id')->constrained();
            $table->foreignUuid('asset_id')->constrained();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('returned_quantity', 10, 2)->default(0);
            $table->string('line_condition')->nullable();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'asset_id']);
            $table->index(['organization_id', 'custody_transaction_id']);
        });

        Schema::create('acknowledgements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('custody_transaction_id')->constrained();
            $table->foreignUuid('acknowledged_by_user_id')->constrained('users');
            $table->timestampTz('acknowledged_at');
            $table->string('role_at_time');
            $table->timestampsTz();

            $table->index(['organization_id', 'custody_transaction_id']);
        });

        Schema::create('maintenance', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('asset_id')->constrained();
            $table->string('vendor')->nullable();
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->decimal('actual_cost', 14, 2)->nullable();
            $table->timestampTz('next_service_due_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'asset_id']);
        });

        Schema::create('asset_incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('asset_id')->constrained();
            $table->enum('incident_type', ['damage', 'loss', 'write_off_request']);
            $table->timestampTz('occurred_at');
            $table->string('location')->nullable();
            $table->text('description');
            $table->json('photo_attachment_ids')->nullable();
            $table->foreignUuid('reported_by_user_id')->constrained('users');
            $table->decimal('estimated_repair_cost', 14, 2)->nullable();
            $table->foreignUuid('reviewed_by_user_id')->nullable()->constrained('users');
            $table->enum('decision', ['repair', 'write_off', 'no_action'])->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'asset_id']);
        });

        Schema::create('stocktakes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->enum('scope_type', ['site', 'warehouse']);
            $table->uuid('scope_id');
            $table->timestampTz('session_started_at');
            $table->json('expected_snapshot');
            $table->enum('status', ['in_progress', 'completed'])->default('in_progress');
            $table->foreignUuid('performed_by_user_id')->constrained('users');
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'scope_type', 'scope_id']);
        });

        Schema::create('stocktake_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('stocktake_id')->constrained();
            $table->foreignUuid('asset_id')->constrained();
            $table->decimal('expected_quantity', 10, 2);
            $table->decimal('counted_quantity', 10, 2)->nullable();
            // FK deferred below — self-referencing ->constrained() inside the
            // same Schema::create() fails on real Postgres (docs/decisions.md).
            $table->uuid('recount_of_line_id')->nullable();
            // A raw scan never directly changes the accounting balance
            // (data-model.md explicit: "სკანირება პირდაპირ არ ცვლის
            // საბუღალტრო ნაშთს") — polymorphic pointer to whichever real
            // adjustment record actually changed the ledger, left null until
            // one exists.
            $table->string('variance_approved_adjustment_type')->nullable();
            $table->uuid('variance_approved_adjustment_id')->nullable();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'stocktake_id']);
        });

        Schema::table('stocktake_lines', function (Blueprint $table) {
            $table->foreign('recount_of_line_id')->references('id')->on('stocktake_lines')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocktake_lines');
        Schema::dropIfExists('stocktakes');
        Schema::dropIfExists('asset_incidents');
        Schema::dropIfExists('maintenance');
        Schema::dropIfExists('acknowledgements');
        Schema::dropIfExists('custody_lines');

        Schema::table('asset_active_custody', function (Blueprint $table) {
            $table->dropForeign(['current_custody_transaction_id']);
        });

        Schema::dropIfExists('custody_transactions');
        Schema::dropIfExists('asset_active_custody');

        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['initial_location_id']);
        });

        Schema::dropIfExists('asset_locations');
        Schema::dropIfExists('asset_kits');
        Schema::dropIfExists('assets');
    }
};
