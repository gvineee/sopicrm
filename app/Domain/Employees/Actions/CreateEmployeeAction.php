<?php

namespace App\Domain\Employees\Actions;

use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\Employment;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * spec section 5 employee profile fields. Creating an Employee also opens
 * its first `Employment` row (started_at = the date given, status=active) —
 * an Employee without any Employment history would make the termination
 * workflow (App\Domain\Employees\Actions\TerminateEmploymentAction) and
 * attendance/payroll's own "is this employee currently employed" checks
 * meaningless.
 */
class CreateEmployeeAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{internal_code: string, first_name: string, last_name: string, phone?: string|null, personal_id_number?: string|null, photo_attachment_id?: string|null, position?: string|null, profession_skills?: array<int, string>|null, team_id?: string|null, supervisor_employee_id?: string|null, emergency_contact_name?: string|null, emergency_contact_phone?: string|null, employment_started_at: string}  $data
     */
    public function execute(array $data, User $actor): Employee
    {
        return DB::transaction(function () use ($data, $actor) {
            $employee = Employee::query()->create([
                'internal_code' => $data['internal_code'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => $data['phone'] ?? null,
                'personal_id_number_encrypted' => $data['personal_id_number'] ?? null,
                'photo_attachment_id' => $data['photo_attachment_id'] ?? null,
                'position' => $data['position'] ?? null,
                'profession_skills' => $data['profession_skills'] ?? null,
                'team_id' => $data['team_id'] ?? null,
                'supervisor_employee_id' => $data['supervisor_employee_id'] ?? null,
                'status' => 'active',
                'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
                'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
            ]);

            Employment::query()->create([
                'employee_id' => $employee->id,
                'started_at' => $data['employment_started_at'],
                'status' => 'active',
            ]);

            $this->auditLogger->log(
                action: 'employees.employee.created',
                target: $employee,
                after: $employee->only(['internal_code', 'first_name', 'last_name', 'position', 'team_id', 'status']),
                actor: $actor,
            );

            return $employee->fresh();
        });
    }
}
