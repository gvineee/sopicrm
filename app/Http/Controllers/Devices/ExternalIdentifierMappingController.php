<?php

namespace App\Http\Controllers\Devices;

use App\Domain\Attendance\Models\RawAccessEvent;
use App\Domain\Devices\Actions\ConfirmExternalIdentifierMappingAction;
use App\Domain\Devices\Actions\IgnoreExternalIdentifierMappingAction;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\ExternalIdentifierMapping;
use App\Domain\Employees\Models\Employee;
use App\Http\Controllers\Controller;
use App\Http\Requests\Devices\ConfirmExternalIdentifierMappingRequest;
use App\Http\Requests\Devices\IgnoreExternalIdentifierMappingRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BIO-02: the admin triage page for an external (BioStar) card this CRM does
 * not yet recognize — App\Domain\Devices\Actions\IngestRawAccessEventAction
 * already never auto-creates an Employee for one; this is what makes it
 * discoverable and resolvable instead of only visible in a raw DB query.
 */
class ExternalIdentifierMappingController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ExternalIdentifierMapping::class);

        $includeResolved = $request->boolean('include_resolved');

        $mappings = ExternalIdentifierMapping::query()
            ->when(! $includeResolved, fn ($query) => $query->pending())
            ->with('confirmedBy')
            ->orderByDesc('first_seen_at')
            ->paginate(50)
            ->withQueryString();

        $refs = collect($mappings->items())->pluck('external_identifier');

        // One aggregated query instead of one per row: how many times this
        // still-unrecognized card has actually swiped and across what time
        // span — the context a reviewer needs to judge "is this real"
        // before picking an employee.
        //
        // `device_id` is deliberately NOT aggregated here: it is a uuid
        // column, and PostgreSQL has no `max(uuid)` aggregate at all
        // (`SQLSTATE[42883]: function max(uuid) does not exist`) — this
        // page 500'd in production for exactly that reason while the
        // SQLite-backed test suite accepted the same SQL happily. Even
        // where it "works", picking the lexicographically largest UUID is
        // meaningless: the reviewer wants the device of the card's most
        // RECENT swipe, which is what the second query below resolves, by
        // time, with a stable id tie-breaker.
        $eventStats = RawAccessEvent::query()
            ->whereIn('unmatched_credential_ref', $refs)
            ->selectRaw('unmatched_credential_ref, count(*) as event_count, min(normalized_event_time_utc) as first_event_at, max(normalized_event_time_utc) as last_event_at')
            ->groupBy('unmatched_credential_ref')
            ->get()
            ->keyBy('unmatched_credential_ref');

        // Bounded by the page's own refs AND by the exact last-event
        // timestamps computed above, so this never loads a busy card's
        // entire event history just to name one device.
        $latestDeviceByRef = RawAccessEvent::query()
            ->whereIn('unmatched_credential_ref', $refs)
            ->whereIn('normalized_event_time_utc', $eventStats->pluck('last_event_at')->filter()->all())
            ->orderByDesc('normalized_event_time_utc')
            ->orderByDesc('id')
            ->get(['unmatched_credential_ref', 'device_id'])
            ->unique('unmatched_credential_ref')
            ->keyBy('unmatched_credential_ref');

        $deviceNames = Device::query()
            ->whereIn('id', $latestDeviceByRef->pluck('device_id')->filter()->all())
            ->pluck('serial_number', 'id');

        $mappings->through(function (ExternalIdentifierMapping $mapping) use ($eventStats, $latestDeviceByRef, $deviceNames) {
            /** @var RawAccessEvent|null $stats */
            $stats = $eventStats->get($mapping->external_identifier);

            return [
                'id' => $mapping->id,
                'source_system' => $mapping->source_system,
                'external_type' => $mapping->external_type,
                'external_identifier' => $mapping->external_identifier,
                'status' => $mapping->status,
                'first_seen_at' => $mapping->first_seen_at->toIso8601String(),
                'confirmed_by' => $mapping->confirmedBy?->name,
                'confirmed_at' => $mapping->confirmed_at?->toIso8601String(),
                'note' => $mapping->note,
                'event_count' => $stats === null ? 0 : $stats->getAttribute('event_count'),
                'first_event_at' => $stats?->getAttribute('first_event_at'),
                'last_event_at' => $stats?->getAttribute('last_event_at'),
                'sample_device_serial' => $deviceNames->get(
                    $latestDeviceByRef->get($mapping->external_identifier)?->getAttribute('device_id')
                ),
            ];
        });

        return Inertia::render('Devices/ExternalMappings/Index', [
            'mappings' => $mappings,
            'includeResolved' => $includeResolved,
            'employees' => Employee::query()->where('status', 'active')->orderBy('last_name')->get(['id', 'first_name', 'last_name']),
            'canManage' => $request->user()->can('devices.external_mappings.manage'),
        ]);
    }

    public function confirm(
        ConfirmExternalIdentifierMappingRequest $request,
        ExternalIdentifierMapping $mapping,
        ConfirmExternalIdentifierMappingAction $action,
    ): RedirectResponse {
        $this->authorize('manage', $mapping);

        $employee = Employee::query()->findOrFail((string) $request->validated('employee_id'));

        $action->execute(
            mapping: $mapping,
            employee: $employee,
            validFrom: $request->validated('valid_from') ?: null,
            siteIds: $request->validated('site_ids') ?: null,
            actor: $request->user(),
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'ბარათი დაუკავშირდა თანამშრომელს და დასწრება ხელახლა დამუშავდა.',
        ]);
    }

    public function ignore(
        IgnoreExternalIdentifierMappingRequest $request,
        ExternalIdentifierMapping $mapping,
        IgnoreExternalIdentifierMappingAction $action,
    ): RedirectResponse {
        $this->authorize('manage', $mapping);

        $action->execute($mapping, $request->validated('note') ?: null, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'ჩანაწერი იგნორირებულია.',
        ]);
    }
}
