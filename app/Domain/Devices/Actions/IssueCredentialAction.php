<?php

namespace App\Domain\Devices\Actions;

use App\Domain\Devices\DataTransferObjects\NormalizedCardNumber;
use App\Domain\Devices\Exceptions\DuplicateActiveCredentialAssignmentException;
use App\Domain\Devices\Models\Credential;
use App\Domain\Devices\Models\CredentialAssignment;
use App\Domain\Devices\Models\Device;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Spec section 6: card form (card type, canonical identifier, employee,
 * validity period, site/access-group scope, status) plus the hard rule
 * "ბარათის აქტიური მინიჭება უნიკალურია კომპანიის ფარგლებში."
 *
 * Finds-or-creates the `credentials` row from the normalized card number
 * (preserving raw bytes/bit length/leading zeros as given by
 * App\Domain\Devices\Services\CardIdentifierNormalizer), then creates a new
 * `active` CredentialAssignment. Refuses if the credential already has an
 * active assignment — that case is a reissue
 * (App\Domain\Devices\Actions\ReissueCredentialAction), never a second
 * concurrent issue.
 */
class IssueCredentialAction
{
    public function __construct(
        private readonly EnqueueDeviceSyncCommandAction $enqueue,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string>|null  $siteIds  Devices at these sites receive
     *                                       the add_user command; null/empty
     *                                       means "every device in the
     *                                       organization" (routine decision —
     *                                       see docs/decisions.md — pending a
     *                                       real access-group→device mapping
     *                                       UI beyond P1 scope).
     */
    public function execute(
        NormalizedCardNumber $cardNumber,
        string $employeeId,
        ?string $validFrom = null,
        ?string $validTo = null,
        ?array $siteIds = null,
        ?User $actor = null,
    ): CredentialAssignment {
        return DB::transaction(function () use ($cardNumber, $employeeId, $validFrom, $validTo, $siteIds, $actor) {
            $credential = Credential::query()->firstOrCreate(
                [
                    'card_type' => $cardNumber->cardType,
                    'canonical_identifier' => $cardNumber->canonicalIdentifier,
                ],
                [
                    'raw_bytes' => $cardNumber->rawBytesHex !== null ? hex2bin($cardNumber->rawBytesHex) : null,
                    'bit_length' => $cardNumber->bitLength,
                    'leading_zeros_preserved' => $cardNumber->leadingZerosPreserved,
                    'status' => 'unassigned',
                ],
            );

            $existingActive = CredentialAssignment::query()
                ->where('credential_id', $credential->id)
                ->active()
                ->lockForUpdate()
                ->first();

            if ($existingActive !== null) {
                throw DuplicateActiveCredentialAssignmentException::forCredential($credential->id);
            }

            $assignment = CredentialAssignment::create([
                'credential_id' => $credential->id,
                'employee_id' => $employeeId,
                'valid_from' => $validFrom ? Carbon::parse($validFrom) : now(),
                'valid_to' => $validTo ? Carbon::parse($validTo) : null,
                'site_scope' => $siteIds,
                'status' => 'active',
            ]);

            $credential->update(['status' => 'issued']);

            // The connector process has no direct access to Laravel's own
            // database — this payload must carry everything a real adapter
            // needs to create/update the corresponding user on the vendor
            // side without a callback, not just the credential fields.
            $employee = Employee::query()->findOrFail($employeeId);

            foreach ($this->relevantDevices($siteIds) as $device) {
                $this->enqueue->execute($device, 'add_user', [
                    'employee_id' => $employeeId,
                    'employee_internal_code' => $employee->internal_code,
                    'employee_name' => trim("{$employee->first_name} {$employee->last_name}"),
                    'card_type' => $credential->card_type,
                    'canonical_identifier' => $credential->canonical_identifier,
                ], $assignment, "issue:{$assignment->id}");
            }

            $this->audit->log(
                action: 'devices.credential.issued',
                target: $credential,
                after: ['employee_id' => $employeeId, 'assignment_id' => $assignment->id],
                actor: $actor,
            );

            return $assignment;
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
