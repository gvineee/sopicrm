<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration (docs/architecture.md §3.5) — the Assets/Tools & Custody
 * module agent found a genuine gap in the already-applied
 * 2026_09_16_090190_create_assets_domain_tables.php: `stocktake_lines` has
 * `variance_approved_adjustment_type`/`variance_approved_adjustment_id` as a
 * polymorphic pointer to "whatever adjustment record actually changes the
 * ledger" (docs/data-model.md, spec section 9.6: "სკანირება პირდაპირ არ
 * ცვლის საბუღალტრო ნაშთს" — a raw scan never directly changes the balance),
 * but no concrete adjustment model existed for it to point at. Logged in
 * docs/decisions.md.
 *
 * `stocktake_adjustments` is that concrete, append-only record: it is the
 * ONLY thing that ever mutates `assets.quantity_on_hand` (for
 * quantity/consumable tracked assets) or an individually-tracked asset's
 * condition/location as the result of a stocktake variance — never the scan
 * itself (App\Domain\Assets\Actions\ScanStocktakeLineAction only ever writes
 * `stocktake_lines.counted_quantity`). Creating one requires an authorized
 * approval (`approved_by_user_id`/`approved_at`), matching the spec's
 * "დამტკიცებული კორექტირება" (approved adjustment) requirement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocktake_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->foreignUuid('stocktake_line_id')->constrained();
            $table->foreignUuid('asset_id')->constrained();
            $table->enum('adjustment_type', ['quantity_correction', 'marked_lost', 'confirmed_found']);
            $table->decimal('quantity_before', 12, 2)->nullable();
            $table->decimal('quantity_after', 12, 2)->nullable();
            $table->foreignUuid('approved_by_user_id')->constrained('users');
            $table->timestampTz('approved_at');
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unsignedInteger('version')->default(1);

            $table->index(['organization_id', 'asset_id']);
            $table->index(['organization_id', 'stocktake_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocktake_adjustments');
    }
};
