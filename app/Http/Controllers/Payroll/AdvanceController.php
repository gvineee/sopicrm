<?php

namespace App\Http\Controllers\Payroll;

use App\Domain\Employees\Models\Employee;
use App\Domain\Payroll\Actions\GrantAdvanceAction;
use App\Domain\Payroll\Models\Advance;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\GrantAdvanceRequest;
use App\Http\Resources\Payroll\AdvanceResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdvanceController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Advance::class);

        $advances = Advance::query()
            ->with('employee')
            ->orderByDesc('granted_at')
            ->get();

        return Inertia::render('Payroll/Advances/Index', [
            'advances' => AdvanceResource::collection($advances),
            'employees' => Employee::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'canManage' => $request->user()->can('create', Advance::class),
        ]);
    }

    public function store(GrantAdvanceRequest $request, GrantAdvanceAction $action): RedirectResponse
    {
        $this->authorize('create', Advance::class);

        $employee = Employee::query()->findOrFail((string) $request->validated('employee_id'));

        $action->execute(
            $employee,
            (string) $request->validated('amount'),
            strtoupper((string) $request->validated('currency')),
            (string) $request->validated('reason'),
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'ავანსი გაცემულია.']);
    }
}
