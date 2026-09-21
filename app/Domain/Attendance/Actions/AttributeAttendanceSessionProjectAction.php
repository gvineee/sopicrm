<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use InvalidArgumentException;

/**
 * Manual override for the REQ-ATT-08 cases ProjectAttributionResolver
 * deliberately leaves null (zero or multiple candidate projects at the
 * session's site) — a human picks explicitly, never a guess.
 */
class AttributeAttendanceSessionProjectAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(AttendanceSession $session, string $projectId, User $actor): AttendanceSession
    {
        $project = Project::query()->findOrFail($projectId);

        if ($project->site_id !== $session->site_id) {
            throw new InvalidArgumentException('Selected project is not sited at this session\'s site.');
        }

        $before = $session->only(['project_id']);

        $session->update(['project_id' => $project->id]);

        $this->auditLogger->log(
            action: 'attendance.session.project_attributed',
            target: $session,
            before: $before,
            after: $session->only(['project_id']),
            actor: $actor,
        );

        return $session->fresh();
    }
}
