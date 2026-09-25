<?php

namespace App\Policies;

use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\Team;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskAssignee;
use App\Domain\Tasks\Models\TaskSubmission;
use App\Domain\Tasks\Services\ReviewerIndependence;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Spec section 3 role table applied to Tasks: PM/owner get project-wide
 * access via the permission + ProjectMembership pattern (see
 * app/Policies/ProjectPolicy.php, the reference implementation); a
 * `თანამშრომელი` (employee) never gets `tasks.tasks.view`/`.manage` at
 * all — their access is ALWAYS ownership-based ("საკუთარი დავალებები",
 * spec explicit), so an employee who is not the accountable owner, an
 * assignee, or a member of an assigned brigade is denied here regardless
 * of being a project member — this is exactly the mandatory test row
 * "თანამშრომელი სხვის task/file URL-ს ხსნის → წვდომა უარყოფილია სერვერზე".
 *
 * `foreman` additionally sees/acts on tasks assigned to a brigade they lead
 * ("თავისი ბრიგადის ამოცანები"), even without `tasks.tasks.manage`.
 */
class TaskPolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $this->hasProjectAccess($user, $project, 'tasks.tasks.view');
    }

    public function view(User $user, Task $task): bool
    {
        if ($task->organization_id !== $user->organization_id) {
            return false;
        }

        if ($this->isPerformer($user, $task)) {
            return true;
        }

        return $this->hasProjectAccess($user, $task->project, 'tasks.tasks.view');
    }

    public function create(User $user, Project $project): bool
    {
        return $this->hasProjectAccess($user, $project, 'tasks.tasks.manage');
    }

    public function update(User $user, Task $task): bool
    {
        if ($task->organization_id !== $user->organization_id) {
            return false;
        }

        return $this->hasProjectAccess($user, $task->project, 'tasks.tasks.manage');
    }

    public function manageDependencies(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    public function assign(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    /**
     * Starting work, toggling the checklist, uploading evidence: the
     * performer doing the actual work, or a manager overseeing the project.
     */
    public function work(User $user, Task $task): bool
    {
        if ($task->organization_id !== $user->organization_id) {
            return false;
        }

        if ($this->isPerformer($user, $task)) {
            return true;
        }

        return $this->hasProjectAccess($user, $task->project, 'tasks.tasks.manage');
    }

    public function block(User $user, Task $task): bool
    {
        return $this->work($user, $task);
    }

    public function unblock(User $user, Task $task): bool
    {
        return $this->work($user, $task);
    }

    /**
     * The employee's own "დავასრულე" (mark done) action — hard rule: an
     * employee can NEVER submit/close someone else's task, so this is
     * ownership-only, never permission-based.
     */
    public function submit(User $user, Task $task): bool
    {
        if ($task->organization_id !== $user->organization_id) {
            return false;
        }

        return $this->isPerformer($user, $task);
    }

    /**
     * Final acceptance (03-Construction-Task-Manager-Spec-KA.md §1, TM-01):
     * an authorized reviewer with `tasks.tasks.accept` on the project who is
     * a DIFFERENT REAL PERSON from whoever performed the work.
     *
     * The previous `self_close_allowed` carve-out — which let the accountable
     * owner accept their own submission whenever a manager had pre-enabled
     * the flag — is gone. The column survives as a historical field (§17)
     * but nothing in this workflow reads it any more. Removing it here is
     * not enough on its own, so the reviewer-independence rule is also
     * re-checked inside the accepting transaction (§13.2); see
     * App\Domain\Tasks\Services\ReviewerIndependence.
     *
     * `$submission` is optional only so the Show page can ask the coarse
     * "could this user ever review here" question when rendering. Every
     * write path passes the specific submission, which is what makes the
     * answer depend on who did THAT work rather than on who happens to be
     * assigned to the task right now (TM-02).
     */
    public function acceptSubmission(User $user, Task $task, ?TaskSubmission $submission = null): bool
    {
        return $this->canReview($user, $task, $submission);
    }

    /**
     * Returning a submission with a comment is the same authority as
     * accepting it, under the same independence rule: someone who worked on
     * this submission cannot sit in judgement over it in either direction.
     */
    public function returnSubmission(User $user, Task $task, ?TaskSubmission $submission = null): bool
    {
        return $this->canReview($user, $task, $submission);
    }

    private function canReview(User $user, Task $task, ?TaskSubmission $submission): bool
    {
        if ($task->organization_id !== $user->organization_id) {
            return false;
        }

        // A performer of this task can never be its reviewer, whatever
        // permissions or roles they also hold — holding many roles does not
        // make one human into two independent people (§4).
        if ($this->isPerformer($user, $task)) {
            return false;
        }

        if ($submission !== null) {
            // TM-07/SEC-01: a submission id from another task never grants
            // review rights here, even for a reviewer authorized on this one.
            if ($submission->task_id !== $task->id || $submission->organization_id !== $task->organization_id) {
                return false;
            }

            if (app(ReviewerIndependence::class)->violationFor($submission, $user, $task) !== null) {
                return false;
            }
        }

        return $this->hasProjectAccess($user, $task->project, 'tasks.tasks.accept');
    }

    public function cancel(User $user, Task $task): bool
    {
        if ($task->organization_id !== $user->organization_id) {
            return false;
        }

        return $this->hasProjectAccess($user, $task->project, 'tasks.tasks.cancel');
    }

    public function reopen(User $user, Task $task): bool
    {
        if ($task->organization_id !== $user->organization_id) {
            return false;
        }

        return $this->hasProjectAccess($user, $task->project, 'tasks.tasks.reopen');
    }

    /**
     * SQL-level counterpart to `isPerformer()`, for a *list* of tasks
     * instead of one already-loaded row (audit finding FIX-02/A3,
     * 2026-09-21: `DashboardController` showed every task in a visible
     * project's title to any project member, even one with no
     * `tasks.tasks.view` permission and no performer relationship to that
     * specific task — exactly what `view()`/`isPerformer()` would deny them
     * on the task's own page). Callers apply this only when the user lacks
     * project-wide `tasks.tasks.view`; keep the two methods' conditions in
     * sync — they express the same rule.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeVisibleToPerformer(Builder $query, User $user): Builder
    {
        $employee = $this->employeeOf($user);

        if ($employee === null) {
            return $query->whereRaw('1 = 0');
        }

        $teamIds = collect([$employee->team_id])->filter()->values();

        if ($user->hasRole('foreman')) {
            $teamIds = $teamIds->merge(
                Team::query()->where('foreman_employee_id', $employee->id)->pluck('id')
            );
        }

        return $query->where(function (Builder $q) use ($employee, $teamIds): void {
            $q->where('accountable_owner_employee_id', $employee->id)
                ->orWhereHas('assignees', function (Builder $assignees) use ($employee, $teamIds): void {
                    $assignees->where('employee_id', $employee->id);

                    if ($teamIds->isNotEmpty()) {
                        $assignees->orWhereIn('team_id', $teamIds);
                    }
                });
        });
    }

    private function isPerformer(User $user, Task $task): bool
    {
        $employee = $this->employeeOf($user);
        if ($employee === null) {
            return false;
        }

        if ($task->accountable_owner_employee_id === $employee->id) {
            return true;
        }

        $isDirectAssignee = TaskAssignee::query()
            ->where('task_id', $task->id)
            ->where('employee_id', $employee->id)
            ->exists();

        if ($isDirectAssignee) {
            return true;
        }

        if ($employee->team_id !== null) {
            $isTeamAssignee = TaskAssignee::query()
                ->where('task_id', $task->id)
                ->where('team_id', $employee->team_id)
                ->exists();

            if ($isTeamAssignee) {
                return true;
            }
        }

        if ($user->hasRole('foreman')) {
            $ledTeamIds = Team::query()->where('foreman_employee_id', $employee->id)->pluck('id');

            if ($ledTeamIds->isNotEmpty()
                && TaskAssignee::query()->where('task_id', $task->id)->whereIn('team_id', $ledTeamIds)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The employee record behind an account, for the purpose of deciding what
     * they may do on a task.
     *
     * A person still awaiting verification is deliberately not one. They were
     * created from a BioStar door enrolment, which says they can open a door
     * and nothing about which department they belong to or what they may do
     * here — so until somebody with the authority has vouched for them, they
     * are neither a performer nor a reviewer. Their badge reads are still
     * attributed to them; it is standing in the workflow they do not have yet.
     */
    private function employeeOf(User $user): ?Employee
    {
        return Employee::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', Employee::STATUS_PENDING_VERIFICATION)
            ->first();
    }

    /**
     * `$project` is nullable because every caller passes `$task->project`,
     * which resolves through the tenant scope and therefore comes back null
     * whenever the task's project is not readable in the current tenant
     * context. An authorization check must answer that with "no" — the
     * previous non-nullable signature turned it into a TypeError, which
     * fails the request with a 500 instead of a clean denial.
     */
    private function hasProjectAccess(User $user, ?Project $project, string $permission): bool
    {
        if ($project === null || $project->organization_id !== $user->organization_id) {
            return false;
        }

        if (! $user->can($permission)) {
            return false;
        }

        if ($user->hasRole('owner')) {
            return true;
        }

        return $user->isActiveMemberOfProject($project->id);
    }
}
