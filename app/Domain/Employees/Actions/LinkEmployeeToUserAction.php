<?php

namespace App\Domain\Employees\Actions;

use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\EmployeeInvite;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Audit A11: „ჩემი დღე" and „ჩემი პროფილი" showed every unlinked user a
 * dead end, because the ONLY way an `employees.user_id` link could ever come
 * into existence was AcceptEmployeeInviteAction — which creates a brand new
 * User. Anyone who already had an account (the person who set the
 * organization up, an administrator, a user created any other way) could
 * therefore never be connected to their own employee record, by any route,
 * and every page that reads "who am I as an employee" stayed empty forever.
 *
 * This action is the missing half: it attaches an EXISTING account to an
 * existing employee record. Linking is deliberately not the same authority
 * as editing a roster field — it decides who can act in the system as this
 * person — so it is gated by `employees.invites.manage`, exactly like
 * issuing an invite (EmployeePolicy::manageInvite).
 *
 * The rules below are not conveniences; each one is an identity guarantee
 * the rest of the system already assumes:
 *
 *  - One employee record has at most one account, and one account belongs to
 *    at most one employee. The two-person acceptance rule
 *    (App\Domain\Tasks\Services\ReviewerIndependence) compares User ids AND
 *    Employee ids precisely because "one human" must resolve the same way on
 *    both sides; letting one account answer for two employee records would
 *    make a single person look like two independent reviewers.
 *  - Both rows belong to the same organization, checked here and not only in
 *    the tenant scope, because this write joins two identities together.
 *  - A terminated employee is not re-activated by the back door.
 *  - System accounts (the queue/relay actors) never become people.
 *  - Any still-pending invite is revoked in the same transaction. An invite
 *    is an instruction to CREATE a second account for this person; leaving it
 *    live after linking an existing one is how one human ends up with two
 *    logins, which is the exact thing spec section 5 forbids.
 */
class LinkEmployeeToUserAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(Employee $employee, User $account, User $actor): Employee
    {
        return DB::transaction(function () use ($employee, $account, $actor) {
            // Re-read both sides under a lock: "this employee has no account"
            // and "this account is free" are exactly the kind of checks two
            // concurrent requests can both pass on stale copies.
            $lockedEmployee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $lockedAccount = User::query()->lockForUpdate()->findOrFail($account->id);

            if ($lockedEmployee->organization_id !== $actor->organization_id
                || $lockedAccount->organization_id !== $actor->organization_id) {
                throw new RuntimeException('ანგარიში და თანამშრომელი ერთსა და იმავე ორგანიზაციას უნდა ეკუთვნოდნენ.');
            }

            if ($lockedEmployee->status === 'terminated') {
                throw new RuntimeException('დათხოვნილ თანამშრომელზე ანგარიშის დაკავშირება შეუძლებელია.');
            }

            if ($lockedEmployee->user_id !== null) {
                throw new RuntimeException($lockedEmployee->user_id === $lockedAccount->id
                    ? 'ეს ანგარიში უკვე დაკავშირებულია ამ თანამშრომელთან.'
                    : 'თანამშრომელს უკვე აქვს დაკავშირებული ანგარიში.');
            }

            if ($lockedAccount->is_system_account) {
                throw new RuntimeException('სისტემური ანგარიშის თანამშრომელზე დაკავშირება შეუძლებელია.');
            }

            $alreadyLinked = Employee::query()
                ->where('user_id', $lockedAccount->id)
                ->whereKeyNot($lockedEmployee->id)
                ->exists();

            if ($alreadyLinked) {
                throw new RuntimeException('ეს ანგარიში სხვა თანამშრომელზეა უკვე დაკავშირებული. ერთ ანგარიშს მხოლოდ ერთი თანამშრომლის ჩანაწერი შეესაბამება.');
            }

            EmployeeInvite::query()
                ->where('employee_id', $lockedEmployee->id)
                ->where('status', 'pending')
                ->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by_user_id' => $actor->id]);

            $lockedEmployee->user_id = $lockedAccount->id;
            $lockedEmployee->save();

            $this->auditLogger->log(
                action: 'employees.user.linked',
                target: $lockedEmployee,
                before: ['user_id' => null],
                after: ['user_id' => $lockedAccount->id],
                actor: $actor,
            );

            $employee->setRawAttributes($lockedEmployee->getAttributes(), true);

            return $employee;
        });
    }
}
