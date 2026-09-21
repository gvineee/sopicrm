<?php

namespace App\Domain\Devices\Actions;

use App\Domain\Attendance\Actions\ReconstructAttendanceSessionsAction;
use App\Domain\Attendance\Models\RawAccessEvent;
use App\Domain\Devices\Exceptions\ExternalIdentifierMappingAlreadyResolvedException;
use App\Domain\Devices\Exceptions\UnsupportedExternalIdentifierTypeException;
use App\Domain\Devices\Models\Credential;
use App\Domain\Devices\Models\ExternalIdentifierMapping;
use App\Domain\Devices\Services\CardIdentifierNormalizer;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * BIO-02: the reviewer's action for "this unrecognized card is actually
 * Employee X's badge." Deliberately does NOT invent a lighter-weight
 * "just point the mapping at an id" mechanism — it goes through the exact
 * same App\Domain\Devices\Actions\IssueCredentialAction every other credential
 * issuance uses, so the resulting Credential/CredentialAssignment is
 * indistinguishable from one issued the normal way (same uniqueness/active-
 * assignment guards apply — a card already actively held by someone else is
 * rejected here too, not silently reassigned).
 *
 * `validFrom` defaults to just before the EARLIEST already-ingested raw
 * event for this exact reference, so `CredentialAssignment::activeAt()`
 * retroactively covers every historical swipe already sitting in
 * `raw_access_events` with `credential_id = null` — those rows are never
 * rewritten (RawAccessEvent is immutable by design), only re-interpreted by
 * App\Domain\Attendance\Actions\ReconstructAttendanceSessionsAction, which
 * this action re-runs immediately so "confirmed" means "attendance is
 * correct now," not "correct after the next scheduled job."
 */
class ConfirmExternalIdentifierMappingAction
{
    public function __construct(
        private readonly CardIdentifierNormalizer $normalizer,
        private readonly IssueCredentialAction $issueCredential,
        private readonly ReconstructAttendanceSessionsAction $reconstruct,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string>|null  $siteIds
     */
    public function execute(
        ExternalIdentifierMapping $mapping,
        Employee $employee,
        ?string $validFrom,
        ?array $siteIds,
        User $actor,
    ): ExternalIdentifierMapping {
        if ($mapping->status !== 'pending') {
            throw ExternalIdentifierMappingAlreadyResolvedException::forMapping($mapping->id, $mapping->status);
        }

        if ($mapping->external_type !== 'card') {
            throw UnsupportedExternalIdentifierTypeException::forType($mapping->external_type);
        }

        return DB::transaction(function () use ($mapping, $employee, $validFrom, $siteIds, $actor): ExternalIdentifierMapping {
            [$cardType, $rawBytesHex] = explode(':', $mapping->external_identifier, 2);
            $cardNumber = $this->normalizer->fromHex($rawBytesHex, $cardType);

            $effectiveValidFrom = $validFrom ?? $this->earliestKnownEventTime($mapping) ?? now()->toIso8601String();

            $assignment = $this->issueCredential->execute(
                cardNumber: $cardNumber,
                employeeId: $employee->id,
                validFrom: $effectiveValidFrom,
                validTo: null,
                siteIds: $siteIds,
                actor: $actor,
            );

            $mapping->update([
                'status' => 'confirmed',
                'target_type' => Credential::class,
                'target_id' => $assignment->credential_id,
                'confirmed_by_user_id' => $actor->id,
                'confirmed_at' => now(),
            ]);

            // Historical raw events are never rewritten — reconstruction
            // re-reads them fresh and now resolves this employee's ownership
            // correctly because the new CredentialAssignment's valid_from
            // covers them.
            $this->reconstruct->handle($employee, Carbon::parse($effectiveValidFrom), now(), $actor);

            $this->audit->log(
                action: 'devices.external_mapping.confirmed',
                target: $mapping,
                after: ['employee_id' => $employee->id, 'credential_id' => $assignment->credential_id],
                actor: $actor,
            );

            return $mapping->refresh();
        });
    }

    private function earliestKnownEventTime(ExternalIdentifierMapping $mapping): ?string
    {
        $earliest = RawAccessEvent::query()
            ->where('unmatched_credential_ref', $mapping->external_identifier)
            ->min('normalized_event_time_utc');

        return $earliest === null ? null : Carbon::parse($earliest)->subSecond()->toIso8601String();
    }
}
