<?php

namespace App\Domain\Tasks\Services;

use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\Team;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskAssignee;
use App\Domain\Tasks\Models\TaskSubmission;
use App\Models\User;

/**
 * 03-Construction-Task-Manager-Spec-KA.md §1/§4: final acceptance of
 * performed work takes two different real people. The performer confirms
 * performance; an independent authorized person confirms acceptance. A
 * manager, director or owner can never accept their own work, and holding
 * several roles does not turn one human into two reviewers.
 *
 * Two things follow, and this class is the single place both live so the
 * Policy (which answers "may this button exist") and the Domain Action
 * (which answers "may this write happen", re-checked inside the
 * transaction, §13.2) can never drift apart:
 *
 *  - The comparison runs on BOTH identities — the User id and the
 *    Employee/person id. Two logins belonging to one person are still one
 *    person, so either match disqualifies.
 *  - It runs against the SUBMISSION's participation snapshot, not against
 *    whatever the task looks like now (TM-02). The snapshot is frozen at
 *    submission time, so reassigning the task afterwards cannot retroactively
 *    launder a reviewer who actually did the work.
 *
 * A submission written before the snapshot column existed falls back to the
 * task's live relationships. That is weaker, but it is the honest maximum
 * for a row whose history was never recorded — it is never treated as
 * "verified".
 */
class ReviewerIndependence
{
    /**
     * @return array{user_ids: list<string>, employee_ids: list<string>}
     */
    public function snapshotFor(Task $task, Employee $submittingEmployee, ?string $submittingUserId): array
    {
        $employeeIds = collect([$submittingEmployee->id, $task->accountable_owner_employee_id]);
        $userIds = collect([$submittingUserId]);

        $assignees = TaskAssignee::query()->where('task_id', $task->id)->get();

        $employeeIds = $employeeIds->merge($assignees->pluck('employee_id'));

        $teamIds = $assignees->pluck('team_id')->filter()->values();
        if ($teamIds->isNotEmpty()) {
            // Everyone working in an assigned brigade participated in the
            // work this submission covers, including the foreman leading it.
            $employeeIds = $employeeIds
                ->merge(Employee::query()->whereIn('team_id', $teamIds)->pluck('id'))
                ->merge(Team::query()->whereIn('id', $teamIds)->pluck('foreman_employee_id'));
        }

        $employeeIds = $employeeIds->filter()->unique()->values();

        $userIds = $userIds
            ->merge(Employee::query()->whereIn('id', $employeeIds)->pluck('user_id'))
            ->filter()
            ->unique()
            ->values();

        return [
            'user_ids' => array_values(array_map(strval(...), $userIds->all())),
            'employee_ids' => array_values(array_map(strval(...), $employeeIds->all())),
        ];
    }

    /**
     * The Georgian reason this reviewer is not independent of this
     * submission, or null when they are. Callers turn a non-null result into
     * a 403 (Policy) or a validation failure (Action).
     */
    public function violationFor(TaskSubmission $submission, User $reviewer, ?Task $task = null): ?string
    {
        $task ??= $submission->task;

        $reviewerEmployee = Employee::query()->where('user_id', $reviewer->id)->first();

        if ($submission->submitted_by_user_id !== null && $submission->submitted_by_user_id === $reviewer->id) {
            return 'საკუთარი გაგზავნის მიღება/დაბრუნება არ შეიძლება — საჭიროა დამოუკიდებელი შემმოწმებელი.';
        }

        if ($reviewerEmployee !== null && $submission->submitted_by_employee_id === $reviewerEmployee->id) {
            return 'საკუთარი გაგზავნის მიღება/დაბრუნება არ შეიძლება — საჭიროა დამოუკიდებელი შემმოწმებელი.';
        }

        $snapshot = $this->participantsOf($submission, $task);

        if (in_array($reviewer->id, $snapshot['user_ids'], true)) {
            return 'ამ სამუშაოში მონაწილე პირი ვერ იქნება მისი დამმოწმებელი.';
        }

        if ($reviewerEmployee !== null && in_array($reviewerEmployee->id, $snapshot['employee_ids'], true)) {
            return 'ამ სამუშაოში მონაწილე პირი ვერ იქნება მისი დამმოწმებელი.';
        }

        return null;
    }

    /**
     * @return array{user_ids: list<string>, employee_ids: list<string>}
     */
    private function participantsOf(TaskSubmission $submission, Task $task): array
    {
        $snapshot = $submission->participant_snapshot;

        if (isset($snapshot['user_ids'], $snapshot['employee_ids'])) {
            return [
                'user_ids' => array_values(array_filter($snapshot['user_ids'], is_string(...))),
                'employee_ids' => array_values(array_filter($snapshot['employee_ids'], is_string(...))),
            ];
        }

        $submitter = $submission->submittedBy;

        return $submitter === null
            ? ['user_ids' => [], 'employee_ids' => array_filter([$task->accountable_owner_employee_id])]
            : $this->snapshotFor($task, $submitter, $submission->submitted_by_user_id);
    }
}
