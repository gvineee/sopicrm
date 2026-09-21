<?php

namespace App\Http\Resources\Devices;

use App\Domain\Devices\Models\Credential;
use App\Domain\Devices\Models\CredentialAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * spec section 6 credential form. `active_assignment` resolves the current
 * owner by CredentialAssignment.status = 'active' (never a mutable "current
 * owner" pointer on Credential itself) — see App\Domain\Devices\Models\
 * CredentialAssignment's own doc comment. Per-device desired/acknowledged
 * sync state is added separately by the controller
 * (App\Domain\Devices\Services\DeviceDesiredStateResolver), not here, to
 * keep this resource free of N+1 device lookups.
 *
 * @mixin Credential
 */
class CredentialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Credential $credential */
        $credential = $this->resource;
        $active = $this->whenLoaded('assignments', fn () => $credential->assignments->firstWhere('status', 'active'));
        $active = $active instanceof CredentialAssignment ? $active : null;

        return [
            'id' => $credential->id,
            'card_type' => $credential->card_type,
            'canonical_identifier' => $credential->canonical_identifier,
            'raw_bytes_hex' => $credential->raw_bytes !== null ? strtoupper(bin2hex($credential->raw_bytes)) : null,
            'bit_length' => $credential->bit_length,
            'status' => $credential->status,
            'active_assignment' => $active === null ? null : [
                'id' => $active->id,
                'employee_id' => $active->employee_id,
                'employee_name' => $active->relationLoaded('employee') && $active->employee !== null
                    ? trim("{$active->employee->first_name} {$active->employee->last_name}")
                    : null,
                'valid_from' => $active->valid_from->toIso8601String(),
                'valid_to' => $active->valid_to?->toIso8601String(),
                'site_scope' => $active->site_scope,
            ],
            'created_at' => $credential->created_at?->toIso8601String(),
        ];
    }
}
