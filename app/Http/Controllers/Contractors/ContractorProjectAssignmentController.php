<?php

namespace App\Http\Controllers\Contractors;

use App\Domain\Contractors\Actions\AssignContractorToProjectAction;
use App\Domain\Contractors\Models\Contractor;
use App\Domain\Contractors\Models\ContractorContract;
use App\Domain\Contractors\Models\ContractorProjectAssignment;
use App\Domain\Projects\Models\Project;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contractors\AssignContractorToProjectRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

class ContractorProjectAssignmentController extends Controller
{
    use AuthorizesRequests;

    public function store(AssignContractorToProjectRequest $request, Contractor $contractor, Project $project, AssignContractorToProjectAction $action): RedirectResponse
    {
        $this->authorize('update', $contractor);
        $this->authorize('update', $project);

        $contractId = $request->validated('contract_id');
        $contract = is_string($contractId) ? ContractorContract::query()->findOrFail($contractId) : null;

        $action->execute(
            $contractor,
            $project,
            $contract,
            (string) $request->validated('starts_on'),
            $request->validated('ends_on'),
            $request->validated('scope_description'),
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'კონტრაქტორი მიენიჭა პროექტს.']);
    }

    public function destroy(Contractor $contractor, Project $project, ContractorProjectAssignment $assignment): RedirectResponse
    {
        $this->authorize('update', $contractor);
        $this->authorize('update', $project);

        abort_unless($assignment->contractor_id === $contractor->id && $assignment->project_id === $project->id, 404);

        $assignment->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'მინიჭება მოიხსნა.']);
    }
}
