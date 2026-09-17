<?php

namespace App\Http\Controllers\Employees;

use App\Domain\Employees\Actions\SetTeamMembershipAction;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\Team;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\SetTeamMembershipRequest;
use Illuminate\Http\RedirectResponse;

class TeamMembershipController extends Controller
{
    public function store(SetTeamMembershipRequest $request, Employee $employee, SetTeamMembershipAction $action): RedirectResponse
    {
        $this->authorize('update', $employee);

        $teamId = $request->validated('team_id');
        $team = is_string($teamId) ? Team::query()->findOrFail($teamId) : null;

        $action->execute($employee, $team, (string) $request->validated('started_on'), $request->user());

        return to_route('employees.show', $employee)->with('toast', [
            'type' => 'success',
            'message' => 'ბრიგადის წევრობა განახლდა.',
        ]);
    }
}
