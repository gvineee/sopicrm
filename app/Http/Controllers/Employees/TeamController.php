<?php

namespace App\Http\Controllers\Employees;

use App\Domain\Employees\Actions\CreateTeamAction;
use App\Domain\Employees\Actions\UpdateTeamAction;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\Team;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\StoreTeamRequest;
use App\Http\Requests\Employees\UpdateTeamRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Team::class);

        return Inertia::render('Employees/Teams', [
            'teams' => Team::query()
                ->with('foreman:id,first_name,last_name')
                ->withCount('members')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(),
            'employees' => Employee::query()
                ->where('status', 'active')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name']),
        ]);
    }

    public function store(StoreTeamRequest $request, CreateTeamAction $action): RedirectResponse
    {
        $this->authorize('create', Team::class);
        $action->execute($request->teamData(), $request->user());

        return to_route('teams.index')->with('toast', [
            'type' => 'success',
            'message' => 'ბრიგადა შეიქმნა.',
        ]);
    }

    public function update(UpdateTeamRequest $request, Team $team, UpdateTeamAction $action): RedirectResponse
    {
        $this->authorize('update', $team);
        $action->execute($team, $request->teamData(), $request->user());

        return to_route('teams.index')->with('toast', [
            'type' => 'success',
            'message' => 'ბრიგადა განახლდა.',
        ]);
    }
}
