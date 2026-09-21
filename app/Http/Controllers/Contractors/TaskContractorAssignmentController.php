<?php

namespace App\Http\Controllers\Contractors;

use App\Domain\Contractors\Actions\AssignContractorToTaskAction;
use App\Domain\Contractors\Models\Contractor;
use App\Domain\Contractors\Models\ContractorContract;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskAssignee;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contractors\AssignContractorToTaskRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Nested under routes/modules/web-projects.php's task group, per the
 * Contractors module plan — a contractor is always an *additional* performer
 * on a task, never the accountable owner (see AssignContractorToTaskAction).
 */
class TaskContractorAssignmentController extends Controller
{
    use AuthorizesRequests;

    public function store(AssignContractorToTaskRequest $request, Project $project, Task $task, AssignContractorToTaskAction $action): RedirectResponse
    {
        $this->authorize('assign', $task);

        $contractor = Contractor::query()->findOrFail((string) $request->validated('contractor_id'));
        $contract = ContractorContract::query()->findOrFail((string) $request->validated('contract_id'));

        try {
            $action->execute($task, $contractor, $contract, $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'კონტრაქტორი მიენიჭა დავალებას.']);
    }

    public function destroy(Project $project, Task $task, TaskAssignee $assignee): RedirectResponse
    {
        $this->authorize('assign', $task);

        abort_unless($assignee->task_id === $task->id && $assignee->contractor_id !== null, 404);

        $assignee->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'კონტრაქტორის მინიჭება მოიხსნა.']);
    }
}
