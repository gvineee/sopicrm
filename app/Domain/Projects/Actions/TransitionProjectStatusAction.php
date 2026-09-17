<?php

namespace App\Domain\Projects\Actions;

use App\Domain\Projects\Exceptions\StaleProjectVersionException;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Services\ProjectStatusTransitionService;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransitionProjectStatusAction
{
    public function __construct(
        private readonly ProjectStatusTransitionService $transitions,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function execute(Project $project, string $toStatus, ?string $reason, ?int $expectedVersion, User $actor): Project
    {
        return DB::transaction(function () use ($project, $toStatus, $reason, $expectedVersion, $actor): Project {
            if ($expectedVersion !== null && $expectedVersion !== (int) $project->version) {
                throw new StaleProjectVersionException($expectedVersion, (int) $project->version);
            }

            $fromStatus = $project->status;

            $this->transitions->assertCanTransition($fromStatus, $toStatus);

            if ($this->transitions->requiresReason($fromStatus, $toStatus) && trim((string) $reason) === '') {
                throw ValidationException::withMessages([
                    'reason' => ['ამ სტატუსის ცვლილებას მიზეზი სავალდებულოა.'],
                ]);
            }

            $project->update(['status' => $toStatus]);

            $this->auditLogger->log(
                action: 'projects.project.status_changed',
                target: $project,
                before: ['status' => $fromStatus],
                after: ['status' => $toStatus],
                reason: $reason,
                actor: $actor,
            );

            return $project;
        });
    }
}
