<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Models\AttendanceAnomaly;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;

class ResolveAttendanceAnomalyAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(AttendanceAnomaly $anomaly, string $resolutionNote, User $actor): AttendanceAnomaly
    {
        $anomaly->update([
            'resolved_at' => now(),
            'resolution_note' => $resolutionNote,
            'resolved_by_user_id' => $actor->id,
        ]);

        $this->auditLogger->log(
            action: 'attendance.anomaly.resolved',
            target: $anomaly,
            after: $anomaly->only(['resolved_at', 'resolution_note', 'resolved_by_user_id']),
            actor: $actor,
        );

        return $anomaly->fresh();
    }
}
