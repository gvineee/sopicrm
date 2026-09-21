<?php

namespace App\Http\Controllers;

use App\Domain\Attendance\Models\RawAccessEvent;
use App\Domain\Devices\Models\CredentialAssignment;
use App\Domain\Employees\Models\Employee;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every authenticated user's own "ჩემი პროფილი" page — deliberately not
 * gated by any permission beyond authentication, since a person always has
 * the right to see data that is about themselves; the query is always
 * derived from `$request->user()->id`, never from a route parameter, so
 * there is no way to view anyone else's page (no IDOR surface to guard
 * against by construction).
 *
 * Attendance session reconstruction and Payroll calculation (spec sections
 * 7-8) are not built yet (docs/implementation-plan.md REQ-ATT/REQ-TSH/
 * REQ-PAY are all `not-started`) — this page shows only what is real today:
 * the employee's own profile fields and their raw device check-in/out
 * events. It does not fabricate worked-hours, day-count, or salary figures;
 * those sections render an honest "not available yet" state until the
 * Attendance/Payroll modules exist to compute them for real.
 */
class MyProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $employee = Employee::query()
            ->where('user_id', $request->user()->id)
            ->with(['team', 'jobPosition', 'supervisor'])
            ->first();

        $recentAccessEvents = [];

        if ($employee !== null) {
            $credentialIds = CredentialAssignment::query()
                ->where('employee_id', $employee->id)
                ->pluck('credential_id');

            $recentAccessEvents = RawAccessEvent::query()
                ->whereIn('credential_id', $credentialIds)
                ->with('device')
                ->orderByDesc('normalized_event_time_utc')
                ->limit(20)
                ->get()
                ->map(fn (RawAccessEvent $event): array => [
                    'id' => $event->id,
                    'device_name' => $event->device?->name,
                    'direction' => $event->reader_direction_snapshot,
                    'event_code' => $event->event_code,
                    'occurred_at' => $event->normalized_event_time_utc->toIso8601String(),
                ])
                ->all();
        }

        return Inertia::render('Me/Profile', [
            'employee' => $employee === null ? null : [
                'id' => $employee->id,
                'full_name' => trim("{$employee->first_name} {$employee->last_name}"),
                'internal_code' => $employee->internal_code,
                'position_name' => $employee->jobPosition !== null ? $employee->jobPosition->name : $employee->position,
                'team_name' => $employee->team?->name,
                'supervisor_name' => $employee->supervisor !== null
                    ? trim("{$employee->supervisor->first_name} {$employee->supervisor->last_name}")
                    : null,
                'status' => $employee->status,
            ],
            'recentAccessEvents' => $recentAccessEvents,
        ]);
    }
}
