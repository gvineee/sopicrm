<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Models\ShiftAssignment;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;

class EndShiftAssignmentAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(ShiftAssignment $assignment, string $effectiveTo, User $actor): ShiftAssignment
    {
        $before = $assignment->only(['effective_to']);

        $assignment->update(['effective_to' => $effectiveTo]);

        $this->auditLogger->log(
            action: 'attendance.shift_assignment.ended',
            target: $assignment,
            before: $before,
            after: $assignment->only(['effective_to']),
            actor: $actor,
        );

        return $assignment->fresh();
    }
}
