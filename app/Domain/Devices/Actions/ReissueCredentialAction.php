<?php

namespace App\Domain\Devices\Actions;

use App\Domain\Devices\Models\Credential;
use App\Domain\Devices\Models\CredentialAssignment;
use App\Domain\Devices\Models\Device;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Spec section 6 hard rule: "ხელახლა გაცემა ქმნის ახალ ისტორიულ
 * assignment-ს; ძველი მოვლენები ძველ მფლობელს უკავშირდება მოვლენის დროის
 * მიხედვით." The old active assignment (if any) is superseded — never
 * mutated/deleted — so any RawAccessEvent whose time falls within the old
 * assignment's [valid_from, valid_to] window still resolves to the old
 * employee via a time-range query (CredentialAssignment::activeAt()),
 * never via a mutable "current owner" pointer.
 */
class ReissueCredentialAction
{
    public function __construct(
        private readonly EnqueueDeviceSyncCommandAction $enqueue,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string>|null  $siteIds
     */
    public function execute(
        Credential $credential,
        string $newEmployeeId,
        ?string $validFrom = null,
        ?string $validTo = null,
        ?array $siteIds = null,
        ?string $reason = null,
        ?User $actor = null,
    ): CredentialAssignment {
        return DB::transaction(function () use ($credential, $newEmployeeId, $validFrom, $validTo, $siteIds, $reason, $actor) {
            $old = CredentialAssignment::query()
                ->where('credential_id', $credential->id)
                ->active()
                ->lockForUpdate()
                ->first();

            $now = now();

            if ($old !== null) {
                $old->update(['status' => 'superseded', 'valid_to' => $old->valid_to ?? $now]);
            }

            $new = CredentialAssignment::create([
                'credential_id' => $credential->id,
                'employee_id' => $newEmployeeId,
                'valid_from' => $validFrom ? Carbon::parse($validFrom) : $now,
                'valid_to' => $validTo ? Carbon::parse($validTo) : null,
                'site_scope' => $siteIds,
                'status' => 'active',
            ]);

            $credential->update(['status' => 'issued']);

            foreach ($this->relevantDevices($siteIds) as $device) {
                $this->enqueue->execute($device, 'update_user', [
                    'employee_id' => $newEmployeeId,
                    'card_type' => $credential->card_type,
                    'canonical_identifier' => $credential->canonical_identifier,
                ], $new, "reissue:{$new->id}");
            }

            $this->audit->log(
                action: 'devices.credential.reissued',
                target: $credential,
                before: $old ? ['employee_id' => $old->employee_id, 'assignment_id' => $old->id] : null,
                after: ['employee_id' => $newEmployeeId, 'assignment_id' => $new->id],
                reason: $reason,
                actor: $actor,
            );

            return $new;
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
