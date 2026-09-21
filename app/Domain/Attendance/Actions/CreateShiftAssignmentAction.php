<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Models\ShiftAssignment;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;

/**
 * REQ-ATT-02. Unlike RateHistory, overlapping shift assignments for the same
 * employee are not spec-forbidden (an employee can have a documented
 * transition day) — reconstruction always resolves the single applicable
 * template per work_date by taking the most recently effective assignment
 * (see ReconstructAttendanceSessionsAction::resolveShiftTemplate).
 */
class CreateShiftAssignmentAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{employee_id: string, shift_template_id: string, effective_from: string, effective_to: string|null}  $data
     */
    public function execute(array $data, User $actor): ShiftAssignment
    {
        $assignment = ShiftAssignment::query()->create([
            'employee_id' => $data['employee_id'],
            'shift_template_id' => $data['shift_template_id'],
            'effective_from' => $data['effective_from'],
            'effective_to' => $data['effective_to'],
        ]);

        $this->auditLogger->log(
            action: 'attendance.shift_assignment.created',
            target: $assignment,
            after: $assignment->only(['employee_id', 'shift_template_id', 'effective_from', 'effective_to']),
            actor: $actor,
        );

        return $assignment;
    }
}
