<?php

namespace App\Domain\Devices\Actions;

use App\Domain\Devices\Models\CredentialAssignment;
use App\Domain\Devices\Models\ExternalIdentifierMapping;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "This person BioStar just told us about is somebody we already have."
 *
 * The sync cannot work this out for itself and must not guess. On the live
 * install the same human is „ირაკლი ღვინერია" in the CRM and `irakli gvineria`
 * in BioStar — the same person written in two scripts, which no name
 * comparison should be trusted to equate. Nor is a near-match safe in the
 * other direction: silently merging two people who happen to share a name
 * would attribute one person's hours to another.
 *
 * So the decision stays with a human, and this is what carries it out: the
 * BioStar identity, the card and the swipe history move onto the employee the
 * organization already knows, and the provisional record the sync created is
 * removed rather than left behind as a second version of the same person.
 *
 * It refuses to touch anything but a provisional record. An employee who has
 * been verified is one the organization has taken responsibility for, and
 * folding them into another is not a data-entry correction.
 */
class AdoptBiostarPersonAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(Employee $provisional, Employee $existing, User $actor): Employee
    {
        return DB::transaction(function () use ($provisional, $existing, $actor): Employee {
            $source = Employee::query()->lockForUpdate()->findOrFail($provisional->id);
            $target = Employee::query()->lockForUpdate()->findOrFail($existing->id);

            if ($source->id === $target->id) {
                throw ValidationException::withMessages([
                    'employee_id' => 'ერთი და იგივე ჩანაწერი ვერ შეერწყმება საკუთარ თავს.',
                ]);
            }

            if ($source->status !== Employee::STATUS_PENDING_VERIFICATION) {
                throw ValidationException::withMessages([
                    'status' => 'შერწყმა მხოლოდ ვერიფიკაციის მოლოდინში მყოფ ჩანაწერზეა შესაძლებელი.',
                ]);
            }

            if ($source->organization_id !== $target->organization_id) {
                throw ValidationException::withMessages([
                    'employee_id' => 'ორივე ჩანაწერი ერთსა და იმავე ორგანიზაციას უნდა ეკუთვნოდეს.',
                ]);
            }

            if ($target->biostar_user_id !== null && $target->biostar_user_id !== $source->biostar_user_id) {
                throw ValidationException::withMessages([
                    'employee_id' => 'ამ თანამშრომელს უკვე სხვა BioStar-ის იდენტიფიკატორი აქვს.',
                ]);
            }

            $biostarUserId = $source->biostar_user_id;
            $before = $target->only(['biostar_user_id']);

            // The card and its history move first: the assignment explains
            // swipes that already exist, so it is re-pointed rather than
            // recreated, which would re-date every event it accounts for.
            CredentialAssignment::query()
                ->where('employee_id', $source->id)
                ->update(['employee_id' => $target->id]);

            // The provisional row has to give up the identifier before the
            // target can take it — they are unique per organization, which is
            // what stops two employees claiming the same upstream person.
            $source->biostar_user_id = null;
            $source->save();

            $target->biostar_user_id = $biostarUserId;
            $target->save();

            ExternalIdentifierMapping::query()
                ->where('external_type', 'user')
                ->where('external_identifier', (string) $biostarUserId)
                ->update(['target_type' => Employee::class, 'target_id' => $target->id, 'status' => 'confirmed']);

            $this->auditLogger->log(
                action: 'devices.biostar.person_adopted',
                target: $target,
                before: $before,
                after: [
                    'biostar_user_id' => $biostarUserId,
                    'merged_from_employee_id' => $source->id,
                    'merged_from_internal_code' => $source->internal_code,
                ],
                actor: $actor,
            );

            // Deleted, not left standing: a provisional record that survives
            // the merge is a second version of the same person, and the next
            // person reading the roster has no way to tell which is real.
            $source->delete();

            $existing->setRawAttributes($target->getAttributes(), true);

            return $existing;
        });
    }
}
