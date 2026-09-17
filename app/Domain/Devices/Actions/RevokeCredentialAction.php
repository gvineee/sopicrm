<?php

namespace App\Domain\Devices\Actions;

use App\Domain\Devices\Models\CredentialAssignment;
use App\Domain\Devices\Models\Device;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Spec section 6: revocation enqueues a `revoke_credential` sync command per
 * relevant device with a freshly incremented `command_version` — an
 * offline device simply leaves that command `pending`
 * (App\Domain\Devices\Adapters\SimulatorDeviceAdapter::applyCommand()), and
 * the UI must render that as "pending," never "revoked"
 * (App\Domain\Devices\Services\DeviceDesiredStateResolver::stateFor()).
 */
class RevokeCredentialAction
{
    public function __construct(
        private readonly EnqueueDeviceSyncCommandAction $enqueue,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(CredentialAssignment $assignment, ?string $reason = null, ?User $actor = null): CredentialAssignment
    {
        return DB::transaction(function () use ($assignment, $reason, $actor) {
            $assignment->update(['status' => 'revoked', 'valid_to' => $assignment->valid_to ?? now()]);

            $credential = $assignment->credential;
            $credential->update(['status' => 'revoked']);

            $siteIds = $assignment->site_scope;

            foreach ($this->relevantDevices($siteIds) as $device) {
                $this->enqueue->execute($device, 'revoke_credential', [
                    'employee_id' => $assignment->employee_id,
                    'card_type' => $credential->card_type,
                    'canonical_identifier' => $credential->canonical_identifier,
                ], $assignment, "revoke:{$assignment->id}:{$assignment->version}");
            }

            $this->audit->log(
                action: 'devices.credential.revoked',
                target: $credential,
                before: ['status' => 'issued'],
                after: ['status' => 'revoked', 'assignment_id' => $assignment->id],
                reason: $reason,
                actor: $actor,
            );

            return $assignment->refresh();
        });
    }

    /**
     * @param  array<string>|null  $siteIds
     * @return Collection<int, Device>
     */
    private function relevantDevices(?array $siteIds)
    {
        $query = Device::query();

        if (! empty($siteIds)) {
            $query->whereIn('site_id', $siteIds);
        }

        return $query->get();
    }
}
