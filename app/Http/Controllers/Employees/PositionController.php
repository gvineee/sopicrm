<?php

namespace App\Http\Controllers\Employees;

use App\Domain\Employees\Models\Position;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\PositionRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Predefined position list management (spec section 5) — a single simple
 * page, not a full resource CRUD, since a position is just a name + active
 * flag used for filtering/reporting elsewhere (Employees index, future
 * manager-scoped views). Positions are never hard-deleted (matches the
 * Companies/Devices precedent of disabling rather than removing a
 * referenced record) — `is_active` controls whether it still appears as a
 * selectable option for new/edited employees.
 */
class PositionController extends Controller
{
    use AuthorizesRequests;

    public function index(): Response
    {
        $this->authorize('viewAny', Position::class);

        return Inertia::render('Employees/Positions', [
            'positions' => Position::query()
                ->withCount('employees')
                ->orderBy('name')
                ->get()
                ->map(fn (Position $position): array => $this->resource($position)),
            'canManage' => request()->user()->can('create', Position::class),
        ]);
    }

    public function store(PositionRequest $request): RedirectResponse
    {
        $this->authorize('create', Position::class);

        Position::query()->create($request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'პოზიცია დაემატა.']);
    }

    public function update(PositionRequest $request, Position $position): RedirectResponse
    {
        $this->authorize('update', $position);

        $position->update($request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'პოზიცია განახლდა.']);
    }

    /** @return array<string, mixed> */
    private function resource(Position $position): array
    {
        return [
            'id' => $position->id,
            'name' => $position->name,
            'is_active' => $position->is_active,
            'employees_count' => $position->employees_count ?? 0,
        ];
    }
}
