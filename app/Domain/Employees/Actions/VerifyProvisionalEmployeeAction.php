<?php

namespace App\Domain\Employees\Actions;

use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\Team;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The gate a person imported from BioStar passes through before they count as
 * a working member of staff.
 *
 * A BioStar enrolment answers one question: this person can open a door. It
 * says nothing about which department they belong to, what their real internal
 * code is, or whether HR has them on the books. So the sync creates them as
 * `pending_verification` and this is where somebody with the authority looks
 * at them and takes responsibility.
 *
 * Verification requires a department, because that is the thing that was
 * genuinely unknown and the thing everything downstream reads: a brigade
 * decides who a foreman is responsible for, which crew a task can be assigned
 * to, and whose hours belong to whose cost centre. Activating a person with no
 * department would leave them looking complete while still being unusable, and
 * that is the state the pending status exists to make visible.
 */
class VerifyProvisionalEmployeeAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(Employee $employee, Team $team, User $actor, ?string $internalCode = null): Employee
    {
        return DB::transaction(function () use ($employee, $team, $actor, $internalCode): Employee {
            $locked = Employee::query()->lockForUpdate()->findOrFail($employee->id);

            if ($locked->status !== Employee::STATUS_PENDING_VERIFICATION) {
                throw ValidationException::withMessages([
                    'status' => 'ვერიფიკაცია მხოლოდ დასადასტურებელ თანამშრომელზეა შესაძლებელი.',
                ]);
            }

            if ($team->organization_id !== $locked->organization_id) {
                throw ValidationException::withMessages([
                    'team_id' => 'ბრიგადა სხვა ორგანიზაციას ეკუთვნის.',
                ]);
            }

            $before = $locked->only(['status', 'team_id', 'internal_code']);

            $locked->status = Employee::STATUS_ACTIVE;
            $locked->team_id = $team->id;

            // The sync writes a placeholder code that says where the person
            // came from. Verification is where HR replaces it with the real
            // one — and where they may also decide the placeholder stands.
            if (is_string($internalCode) && trim($internalCode) !== '') {
                $locked->internal_code = trim($internalCode);
            }

            $locked->save();

            $this->auditLogger->log(
                action: 'employees.employee.verified',
                target: $locked,
                before: $before,
                after: $locked->only(['status', 'team_id', 'internal_code']) + [
                    'biostar_user_id' => $locked->biostar_user_id,
                ],
                actor: $actor,
            );

            $employee->setRawAttributes($locked->getAttributes(), true);

            return $employee;
        });
    }
}
