<?php

namespace App\Domain\Devices\Actions;

use App\Domain\Devices\Models\Credential;
use App\Domain\Devices\Models\CredentialAssignment;
use App\Domain\Devices\Models\ExternalIdentifierMapping;
use App\Domain\Devices\Services\CardIdentifierNormalizer;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A person enrolled in BioStar, brought across into the CRM.
 *
 * They arrive as `pending_verification`: real enough that their badge reads
 * are attributed to them from the first swipe, and explicitly not yet a
 * working member of staff. No department, no permissions, no login. Somebody
 * with the authority has to look at them and say so — see
 * App\Domain\Employees\Actions\VerifyProvisionalEmployeeAction.
 *
 * Why not create them active: BioStar enrolment is a physical act by whoever
 * holds the access system, and it answers "this person can open a door". It
 * does not answer which department they belong to, what they may do in the
 * CRM, or whether HR has them on the books at all. Creating an active employee
 * from a door enrolment would let the access system quietly grant standing in
 * a system it knows nothing about.
 *
 * What this never does:
 *  - It never writes to BioStar. This whole phase is read-only there.
 *  - It never creates a User account. A login is granted deliberately, by
 *    invite or by linking an existing account, never as a side effect of
 *    somebody being handed a card.
 *  - It never re-activates or renames a person an administrator has already
 *    taken charge of. An upstream record is a source of new people, not an
 *    authority over the ones we already know.
 */
class SyncBiostarPersonAction
{
    public function __construct(
        private readonly CardIdentifierNormalizer $normalizer,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{user_id: string, name?: string|null, phone?: string|null, card_id?: string|null, card_type?: string|null}  $person
     * @return array{employee: Employee, created: bool}
     */
    public function execute(array $person, ?User $actor = null): array
    {
        $biostarUserId = trim((string) $person['user_id']);

        return DB::transaction(function () use ($person, $biostarUserId, $actor) {
            $existing = Employee::query()->where('biostar_user_id', $biostarUserId)->lockForUpdate()->first();

            if ($existing !== null) {
                // Already known. Their card may have been reissued upstream,
                // which is the one thing worth keeping in step; everything
                // else about them is ours now.
                $this->syncCard($existing, $person);
                $this->recordMapping($biostarUserId, $existing);

                return ['employee' => $existing, 'created' => false];
            }

            $employee = Employee::query()->create([
                'biostar_user_id' => $biostarUserId,
                'internal_code' => $this->internalCodeFor($biostarUserId),
                ...$this->splitName($person['name'] ?? null),
                'phone' => $person['phone'] ?? null,
                // Not active: a door enrolment says this person can open a
                // door, not that they belong to a department or may act in
                // this system.
                'status' => Employee::STATUS_PENDING_VERIFICATION,
            ]);

            $this->syncCard($employee, $person);
            $this->recordMapping($biostarUserId, $employee);

            $this->auditLogger->log(
                action: 'devices.biostar.person_imported',
                target: $employee,
                after: [
                    'biostar_user_id' => $biostarUserId,
                    'status' => Employee::STATUS_PENDING_VERIFICATION,
                    'name' => $person['name'] ?? null,
                ],
                actor: $actor,
                actorLabel: $actor === null ? 'biostar:sync' : null,
            );

            return ['employee' => $employee, 'created' => true];
        });
    }

    /**
     * Registers the card BioStar holds for this person and points it at them.
     * BioStar reports the card number in DECIMAL; the CRM stores a normalized
     * decimal canonical identifier, so it is converted through the same
     * normalizer the event ingest uses rather than by a second rule that could
     * drift from it.
     *
     * @param  array{card_id?: string|null, card_type?: string|null}  $person
     */
    private function syncCard(Employee $employee, array $person): void
    {
        $cardId = $person['card_id'] ?? null;
        $cardType = $person['card_type'] ?? null;

        if (! is_string($cardId) || ! ctype_digit($cardId) || ! is_string($cardType) || $cardType === '') {
            return;
        }

        $normalized = $this->normalizer->fromDecimal($cardId, $cardType, 32);

        $credential = Credential::query()->firstOrCreate(
            ['card_type' => $normalized->cardType, 'canonical_identifier' => $normalized->canonicalIdentifier],
            ['bit_length' => $normalized->bitLength, 'status' => 'issued'],
        );

        // An assignment that already exists is left exactly as it is: its
        // `valid_from` is when this person actually started carrying the card,
        // and rewriting that would silently re-date every swipe it explains.
        $alreadyAssigned = CredentialAssignment::query()
            ->where('credential_id', $credential->id)
            ->where('employee_id', $employee->id)
            ->whereNull('valid_to')
            ->exists();

        if ($alreadyAssigned) {
            return;
        }

        CredentialAssignment::query()->create([
            'credential_id' => $credential->id,
            'employee_id' => $employee->id,
            // Backdated deliberately: BioStar's own event history reaches
            // further back than this import does, and an assignment that
            // starts "now" would orphan every swipe before it.
            'valid_from' => now()->subYear(),
            'status' => 'active',
        ]);
    }

    /**
     * The BioStar person -> CRM employee link, recorded as confirmed because
     * this employee was created FROM that person. Unlike a swipe from an
     * unknown card, there is nothing here for an administrator to adjudicate.
     */
    private function recordMapping(string $biostarUserId, Employee $employee): void
    {
        $mapping = ExternalIdentifierMapping::query()->firstOrNew([
            'source_system' => 'biostar',
            'source_instance_key' => 'default',
            'external_type' => 'user',
            'external_identifier' => $biostarUserId,
        ]);

        $mapping->target_type = Employee::class;
        $mapping->target_id = $employee->id;
        $mapping->status = 'confirmed';
        $mapping->first_seen_at ??= now();
        $mapping->confirmed_at ??= now();
        $mapping->save();
    }

    /**
     * BioStar carries one `name` field; the CRM has two. Splitting on the
     * last space is a guess, so the whole value is kept as the first name
     * when there is nothing to split — better a person called
     * „ირაკლი ღვინერია" with an empty surname than a surname invented from
     * half a word.
     *
     * @return array{first_name: string, last_name: string}
     */
    private function splitName(?string $name): array
    {
        $name = trim((string) $name);

        if ($name === '') {
            return ['first_name' => 'BioStar', 'last_name' => 'უცნობი'];
        }

        $parts = preg_split('/\s+/', $name) ?: [$name];

        if (count($parts) === 1) {
            return ['first_name' => $parts[0], 'last_name' => ''];
        }

        $last = array_pop($parts);

        return ['first_name' => implode(' ', $parts), 'last_name' => $last];
    }

    /**
     * A placeholder code that says where the person came from. HR replaces it
     * with the real one at verification; until then it must be unique and must
     * not look like a real internal code somebody could act on.
     */
    private function internalCodeFor(string $biostarUserId): string
    {
        return 'BIOSTAR-'.$biostarUserId;
    }
}
