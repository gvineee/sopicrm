<?php

namespace App\Http\Requests\Assets;

use App\Domain\Shared\Services\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:255'],
            'tracking_type' => ['required', 'in:individual,kit_component,quantity,consumable'],
            'inventory_code' => ['required', 'string', 'max:255'],
            // Audit A13. `warehouse` is not offered at registration: there is
            // no `warehouses` table to validate an id against (see
            // App\Domain\Assets\Actions\ReturnCustodyAction), so accepting one
            // here could only ever store an unresolvable identifier. Custody
            // returns that already write `locatable_type='warehouse'` rows are
            // untouched — this is the registration form's input surface only.
            'initial_location_type' => ['required', 'in:site,employee'],
            'initial_location_id' => ['required', 'uuid', $this->locationRule()],
            'condition' => ['required', 'in:new,good,fair,damaged,under_repair,written_off'],
            'brand' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'purchased_at' => ['nullable', 'date'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_currency' => ['nullable', 'string', 'size:3'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'warranty_until' => ['nullable', 'date'],
            'ownership' => ['nullable', 'in:owned,rented'],
            'calibration_due_at' => ['nullable', 'date'],
            'service_due_at' => ['nullable', 'date'],
            'quantity_on_hand' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array{name: string, category: string, tracking_type: string, inventory_code: string, initial_location_type: string, initial_location_id: string, condition: string, brand?: ?string, model?: ?string, serial_number?: ?string, purchased_at?: ?string, purchase_price?: ?string, purchase_currency?: ?string, supplier?: ?string, warranty_until?: ?string, ownership?: ?string, calibration_due_at?: ?string, service_due_at?: ?string, quantity_on_hand?: ?string}
     */
    public function assetData(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'category' => (string) $this->validated('category'),
            'tracking_type' => (string) $this->validated('tracking_type'),
            'inventory_code' => (string) $this->validated('inventory_code'),
            'initial_location_type' => (string) $this->validated('initial_location_type'),
            'initial_location_id' => (string) $this->validated('initial_location_id'),
            'condition' => (string) $this->validated('condition'),
            'brand' => $this->validatedNullableString('brand'),
            'model' => $this->validatedNullableString('model'),
            'serial_number' => $this->validatedNullableString('serial_number'),
            'purchased_at' => $this->validatedNullableString('purchased_at'),
            'purchase_price' => $this->validatedNullableString('purchase_price'),
            'purchase_currency' => $this->validatedNullableString('purchase_currency'),
            'supplier' => $this->validatedNullableString('supplier'),
            'warranty_until' => $this->validatedNullableString('warranty_until'),
            'ownership' => $this->validatedNullableString('ownership'),
            'calibration_due_at' => $this->validatedNullableString('calibration_due_at'),
            'service_due_at' => $this->validatedNullableString('service_due_at'),
            'quantity_on_hand' => $this->validatedNullableString('quantity_on_hand'),
        ];
    }

    /**
     * Audit A13: `initial_location_id` was previously validated as a
     * well-formed uuid and nothing else, so any uuid at all — including a
     * record belonging to another organization — was accepted and stored as
     * the asset's location. The rule resolves against the table the selected
     * type actually names, scoped by the SAME organization id the Eloquent
     * tenant scope uses (`Rule::exists` runs on the query builder, below
     * Eloquent's global scope, so the predicate has to be explicit here).
     */
    private function locationRule(): Exists
    {
        $table = $this->input('initial_location_type') === 'site' ? 'sites' : 'employees';

        return Rule::exists($table, 'id')
            ->where('organization_id', CurrentOrganization::id());
    }

    private function validatedNullableString(string $key): ?string
    {
        $value = $this->validated($key);

        return is_scalar($value) ? (string) $value : null;
    }
}
