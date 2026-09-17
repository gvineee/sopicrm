<?php

namespace App\Domain\Projects\Actions;

use App\Domain\Projects\Exceptions\StaleProjectVersionException;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Updates a Project's own fields (never its `status` — see
 * TransitionProjectStatusAction — so that status changes always go through
 * the state-machine/reason/audit path, never a bare mass-update). Enforces
 * the spec section 4 optimistic-concurrency rule: a caller-supplied
 * `version` that no longer matches the current row is rejected as a 409
 * rather than silently overwritten.
 */
class UpdateProjectAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Project $project, array $data, User $actor): Project
    {
        return DB::transaction(function () use ($project, $data, $actor): Project {
            $expectedVersion = $data['version'] ?? null;
            if ($expectedVersion !== null && (int) $expectedVersion !== (int) $project->version) {
                throw new StaleProjectVersionException((int) $expectedVersion, (int) $project->version);
            }

            [$before] = [$project->only([
                'name', 'code', 'client_id', 'manager_user_id', 'address',
                'starts_on', 'ends_on', 'budget_baseline',
            ])];

            $project->fill([
                'name' => $data['name'] ?? $project->name,
                'code' => $data['code'] ?? $project->code,
                'client_id' => array_key_exists('client_id', $data) ? $data['client_id'] : $project->client_id,
                'manager_user_id' => $data['manager_user_id'] ?? $project->manager_user_id,
                'address' => array_key_exists('address', $data) ? $data['address'] : $project->address,
                'starts_on' => array_key_exists('starts_on', $data) ? $data['starts_on'] : $project->starts_on,
                'ends_on' => array_key_exists('ends_on', $data) ? $data['ends_on'] : $project->ends_on,
                'budget_baseline' => array_key_exists('budget_baseline', $data) ? $data['budget_baseline'] : $project->budget_baseline,
            ]);

            $project->save();

            $this->auditLogger->log(
                action: 'projects.project.updated',
                target: $project,
                before: $before,
                after: $project->only(array_keys($before)),
                actor: $actor,
            );

            return $project;
        });
    }
}
