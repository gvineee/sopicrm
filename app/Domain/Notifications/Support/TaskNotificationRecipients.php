<?php

namespace App\Domain\Notifications\Support;

use App\Domain\Tasks\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * NOTIFY-01: resolves the real User account(s) to notify for a Task's
 * current `task_assignees` rows (App\Domain\Tasks\Models\TaskAssignee) —
 * an individual employee, or every member of an assigned team/brigade. A
 * `contractor_id` assignee is skipped: a Contractor is explicitly not a
 * login account in this codebase (no `user_id`), so there is nothing to
 * notify in-app for that row; Contractors are out of this ticket's scope.
 * An employee with no linked User account (Employee.user_id nullable, per
 * docs/architecture.md's "Employee and login account are separate
 * concepts" rule) is silently skipped too — not an error.
 */
class TaskNotificationRecipients
{
    /**
     * @return Collection<int, User>
     */
    public static function forTask(Task $task): Collection
    {
        $task->loadMissing(['assignees.employee.user', 'assignees.team.members.user']);

        $users = collect();

        foreach ($task->assignees as $assignee) {
            if ($assignee->employee?->user !== null) {
                $users->push($assignee->employee->user);
            }

            if ($assignee->team !== null) {
                foreach ($assignee->team->members as $member) {
                    if ($member->user !== null) {
                        $users->push($member->user);
                    }
                }
            }
        }

        return $users->unique('id')->values();
    }
}
