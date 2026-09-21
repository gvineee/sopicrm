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
        // still-unrecognized card has actually swiped, on which device(s),
        // and across what time span — the context a reviewer needs to judge
        // "is this real" before picking an employee.
        $eventStats = RawAccessEvent::query()
            ->whereIn('unmatched_credential_ref', $refs)
            ->selectRaw('unmatched_credential_ref, count(*) as event_count, min(normalized_event_time_utc) as first_event_at, max(normalized_event_time_utc) as last_event_at, max(device_id) as sample_device_id')
            ->groupBy('unmatched_credential_ref')
            ->get()
            ->keyBy('unmatched_credential_ref');

        $deviceNames = Device::query()
            ->whereIn('id', $eventStats->pluck('sample_device_id')->filter())
            ->pluck('serial_number', 'id');

        $mappings->through(function (ExternalIdentifierMapping $mapping) use ($eventStats, $deviceNames) {
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
                'sample_device_serial' => $stats === null ? null : $deviceNames->get($stats->getAttribute('sample_device_id')),
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
