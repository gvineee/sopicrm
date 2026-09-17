<?php

namespace App\Http\Controllers\Employees;

use App\Domain\Employees\Actions\CreateRateHistoryAction;
use App\Domain\Employees\Exceptions\OverlappingRateException;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\RateHistory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\StoreRateHistoryRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

/**
 * spec section 5 — RateHistory CRUD, nested under an Employee. Rate history
 * rows are never edited/deleted in place once created (append-only, mirroring
 * the rest of this schema's financial-ledger posture) — a correction is a
 * new row with its own effective period, change reason, and approver.
 */
class RateHistoryController extends Controller
{
    use AuthorizesRequests;

    public function store(StoreRateHistoryRequest $request, Employee $employee, CreateRateHistoryAction $action): RedirectResponse
    {
        $this->authorize('create', RateHistory::class);

        try {
            $action->execute($employee, $request->rateData(), $request->user());
        } catch (OverlappingRateException $exception) {
            return back()->withErrors(['effective_from' => $exception->getMessage()])->withInput();
        }

        return to_route('employees.show', $employee)->with('toast', [
            'type' => 'success',
            'message' => 'ტარიფი დამატებულია.',
        ]);
    }
}
