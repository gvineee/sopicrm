<?php

namespace App\Policies;

use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\Team;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskAssignee;
use App\Models\User;

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
     * Final acceptance: normally a manager/reviewer with `tasks.tasks.accept`
     * on the project. The one exception (spec explicit hard rule) is a task
     * where the manager has pre-enabled `self_close_allowed` — then the
     * accountable owner may accept their own submission. This method is the
     * ONLY place that carve-out is authorized; App\Domain\Tasks\Actions\
     * AcceptTaskSubmission does not re-check who is accepting.
     */
    public function acceptSubmission(User $user, Task $task): bool
    {
        if ($task->organization_id !== $user->organization_id) {
            return false;
        }

        $employee = $this->employeeOf($user);
        if ($employee !== null && $task->accountable_owner_employee_id === $employee->id) {
            return (bool) $task->self_close_allowed;
        }

        return $this->hasProjectAccess($user, $task->project, 'tasks.tasks.accept');
    }

    /**
     * Returning a submission with a comment: always a reviewer/manager
     * action — never the same employee who submitted it, self-close policy
     * or not (there is nothing to "return" to yourself).
     */
    public function returnSubmission(User $user, Task $task): bool
    {
        if ($task->organization_id !== $user->organization_id) {
            return false;
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

    private function employeeOf(User $user): ?Employee
    {
        return Employee::query()->where('user_id', $user->id)->first();
    }

    private function hasProjectAccess(User $user, Project $project, string $permission): bool
    {
        if ($project->organization_id !== $user->organization_id) {
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
