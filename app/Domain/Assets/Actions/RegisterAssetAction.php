<?php

namespace App\Domain\Assets\Actions;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetActiveCustody;
use App\Domain\Assets\Models\AssetLocation;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * spec section 9.1 registration form. Required: name, category, tracking
 * type, unique inventory code, initial location, condition. Optional:
 * brand/model/serial/purchase date+price+currency/supplier/warranty/photos/
 * manual/bundle contents/ownership/calibration+service due. A serialized
 * (individually-tracked) asset's quantity is always 1 — enforced here by
 * simply never writing `quantity_on_hand` for tracking_type='individual'
 * (the column stays null, which docs/data-model.md documents as "only
 * meaningful for tracking_type IN ('quantity','consumable')").
 */
class RegisterAssetAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{name: string, category: string, tracking_type: string, inventory_code: string, initial_location_type: string, initial_location_id: string, condition: string, brand?: ?string, model?: ?string, serial_number?: ?string, purchased_at?: ?string, purchase_price?: ?string, purchase_currency?: ?string, supplier?: ?string, warranty_until?: ?string, manual_attachment_id?: ?string, bundle_contents?: ?array<int|string, mixed>, ownership?: ?string, calibration_due_at?: ?string, service_due_at?: ?string, quantity_on_hand?: ?string}  $data
     */
    public function execute(array $data, User $actor): Asset
    {
        return DB::transaction(function () use ($data, $actor) {
            $asset = Asset::query()->create([
                'name' => $data['name'],
                'category' => $data['category'],
                'tracking_type' => $data['tracking_type'],
                'inventory_code' => $data['inventory_code'],
                'condition' => $data['condition'],
                'brand' => $data['brand'] ?? null,
                'model' => $data['model'] ?? null,
                'serial_number' => $data['serial_number'] ?? null,
                'purchased_at' => $data['purchased_at'] ?? null,
                'purchase_price' => $data['purchase_price'] ?? null,
                'purchase_currency' => $data['purchase_currency'] ?? null,
                'supplier' => $data['supplier'] ?? null,
                'warranty_until' => $data['warranty_until'] ?? null,
                'manual_attachment_id' => $data['manual_attachment_id'] ?? null,
                'bundle_contents' => $data['bundle_contents'] ?? null,
                'ownership' => $data['ownership'] ?? 'owned',
                'calibration_due_at' => $data['calibration_due_at'] ?? null,
                'service_due_at' => $data['service_due_at'] ?? null,
                // Individually-tracked assets always represent quantity 1
                // (spec explicit) — never persist a quantity for them.
                'quantity_on_hand' => in_array($data['tracking_type'], ['quantity', 'consumable'], true)
                    ? ($data['quantity_on_hand'] ?? 0)
                    : null,
                // Opaque QR payload (DEC-006 / spec 9): resolving it always
                // re-runs the Policy check server-side; the token itself
                // grants no access.
                'qr_token' => (string) Str::uuid(),
            ]);

            $location = AssetLocation::query()->create([
                'locatable_type' => $data['initial_location_type'],
                'locatable_id' => $data['initial_location_id'],
                'asset_id' => $asset->id,
                'as_of' => now(),
                'is_current' => true,
            ]);

            $asset->forceFill(['initial_location_id' => $location->id])->save();

            AssetActiveCustody::query()->create([
                'asset_id' => $asset->id,
                'status' => 'available',
            ]);

            $this->auditLogger->log(
                action: 'assets.asset.registered',
                target: $asset,
                after: $asset->only(['name', 'category', 'tracking_type', 'inventory_code', 'condition']),
                actor: $actor,
            );

            return $asset->fresh(['currentLocation']);
        });
    }
}
