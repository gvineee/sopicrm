<?php

namespace App\Domain\Auth\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * QUEUE-01 (deferred remainder): the one legitimate way a scheduled command
 * gets a real, auditable `User` row to attribute an automated action to
 * (e.g. `attendance_adjustments.requested_by_user_id`, which is NOT
 * nullable, or an `AuditLogger::log()` actor that should be a real,
 * identifiable account rather than a null gap).
 *
 * Idempotent per organization: looks up an existing `is_system_account`
 * row for that organization first, only creates one if genuinely none
 * exists yet. `is_active = false` is what actually makes it non-loginable
 * (App\Domain\Auth\Support\ActiveUserProvider already gates credential
 * lookup on this column) — the `.invalid` TLD in its email is the real,
 * IANA-reserved TLD for "this address will never be dereferenced/delivered
 * to," used deliberately rather than a made-up domain that could someday
 * be registered by someone else.
 */
class GetOrCreateSystemActorAction
{
    public function execute(string $organizationId): User
    {
        $existing = User::query()
            ->where('organization_id', $organizationId)
            ->where('is_system_account', true)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $user = new User;
        $user->forceFill([
            'organization_id' => $organizationId,
            'current_organization_id' => $organizationId,
            'name' => 'სისტემა (ავტომატური)',
            'email' => "system-automation+{$organizationId}@internal.invalid",
            'password' => Hash::make(Str::random(64)),
            'is_active' => false,
            'is_system_account' => true,
        ]);
        $user->save();

        return $user;
    }
}
