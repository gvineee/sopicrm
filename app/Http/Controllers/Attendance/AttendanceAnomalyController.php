<?php

namespace App\Http\Controllers\Attendance;

use App\Domain\Attendance\Actions\ResolveAttendanceAnomalyAction;
use App\Domain\Attendance\Models\AttendanceAnomaly;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ResolveAnomalyRequest;
use App\Http\Resources\Attendance\AttendanceAnomalyResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceAnomalyController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AttendanceAnomaly::class);

        $anomalies = AttendanceAnomaly::query()
            ->with(['employee', 'device'])
            ->when(
                $request->boolean('include_resolved') === false,
                fn ($query) => $query->unresolved(),
            )
            ->orderByDesc('detected_at')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Attendance/Anomalies/Index', [
            'anomalies' => AttendanceAnomalyResource::collection($anomalies),
            'includeResolved' => $request->boolean('include_resolved'),
        ]);
    }

    public function resolve(ResolveAnomalyRequest $request, AttendanceAnomaly $anomaly, ResolveAttendanceAnomalyAction $action): RedirectResponse
    {
        $this->authorize('resolve', $anomaly);

        $action->execute($anomaly, (string) $request->validated('resolution_note'), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'ანომალია მოგვარებულია.']);
    }
}
