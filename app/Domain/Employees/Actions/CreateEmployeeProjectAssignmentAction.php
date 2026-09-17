<?php

namespace App\Domain\Employees\Actions;

use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\EmployeeProjectAssignment;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;

/**
 * spec section 5 "ობიექტებზე მინიჭების პერიოდები" (site/object assignment
 * periods) — implemented against `employee_project_assignments`
 * (docs/data-model.md maps a project to the employee's working "site" for
 * this schema; see that file's own entry). Overlaps are allowed by design
 * (an employee can be assigned to more than one project's date range at
 * once) — no overlap check here, matching the data model's explicit note.
 */
class CreateEmployeeProjectAssignmentAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{project_id: string, starts_on: string, ends_on?: string|null, assignment_type?: string|null}  $data
     */
    public function execute(Employee $employee, array $data, User $actor): EmployeeProjectAssignment
    {
        $assignment = EmployeeProjectAssignment::query()->create([
            'employee_id' => $employee->id,
            'project_id' => $data['project_id'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null,
            'assignment_type' => $data['assignment_type'] ?? null,
        ]);

        $this->auditLogger->log(
            action: 'employees.project_assignment.created',
            target: $assignment,
            after: $assignment->only(['employee_id', 'project_id', 'starts_on', 'ends_on']),
            actor: $actor,
        );

        return $assignment;
    }
}
