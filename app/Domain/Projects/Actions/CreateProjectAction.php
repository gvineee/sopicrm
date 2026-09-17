<?php

namespace App\Domain\Projects\Actions;

use App\Domain\Auth\Models\ProjectMembership;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a Project and, per spec section 3 ("პროექტის წევრობა და როლის
 * უფლებები ერთად განსაზღვრავს წვდომას"), immediately gives its manager an
 * active ProjectMembership row — otherwise a project manager who is not the
 * `owner` role would create a project they can then never see again.
 */
class CreateProjectAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data, User $actor): Project
    {
        return DB::transaction(function () use ($data, $actor): Project {
            $project = Project::create([
                'name' => $data['name'],
                'code' => $data['code'],
                'client_id' => $data['client_id'] ?? null,
                'manager_user_id' => $data['manager_user_id'],
                'address' => $data['address'] ?? null,
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
                'status' => $data['status'] ?? 'planning',
                'budget_baseline' => $data['budget_baseline'] ?? null,
            ]);

            ProjectMembership::create([
                'project_id' => $project->id,
                'user_id' => $project->manager_user_id,
                'role_context' => 'manager',
                'added_by_user_id' => $actor->id,
            ]);

            $this->auditLogger->log(
                action: 'projects.project.created',
                target: $project,
                after: $project->getAttributes(),
                actor: $actor,
            );

            return $project;
        });
    }
}
