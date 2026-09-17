<?php

namespace App\Domain\Employees\Actions;

use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;

/**
 * spec section 5 profile fields, update path. Does not touch `status`
 * (active/inactive/terminated) — that transition is exclusively
 * App\Domain\Employees\Actions\TerminateEmploymentAction's job, since ending
 * employment has real side effects (login revocation, device sync commands,
 * unreturned-tool surfacing) a plain field edit must never trigger silently.
 */
class UpdateEmployeeAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Employee $employee, array $data, User $actor): Employee
    {
        $allowed = array_intersect_key($data, array_flip([
            'first_name', 'last_name', 'phone', 'personal_id_number',
            'photo_attachment_id', 'position', 'profession_skills',
            'team_id', 'supervisor_employee_id',
            'emergency_contact_name', 'emergency_contact_phone',
        ]));

        if (array_key_exists('personal_id_number', $allowed)) {
            $allowed['personal_id_number_encrypted'] = $allowed['personal_id_number'];
            unset($allowed['personal_id_number']);
        }

        [$before] = [$employee->only(array_keys($allowed))];

        $employee->fill($allowed);
        $employee->save();

        $this->auditLogger->log(
            action: 'employees.employee.updated',
            target: $employee,
            before: $before,
            after: $employee->only(array_keys($allowed)),
            actor: $actor,
        );

        return $employee->fresh();
    }
}
