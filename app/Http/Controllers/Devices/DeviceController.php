<?php

namespace App\Http\Controllers\Devices;

use App\Domain\Attendance\Models\AttendanceAnomaly;
use App\Domain\Devices\Actions\RegisterDeviceAction;
use App\Domain\Devices\Actions\UpdateDeviceAction;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceCapability;
use App\Domain\Devices\Models\DeviceCheckpoint;
use App\Domain\Devices\Models\DeviceSyncCommand;
use App\Domain\Devices\Models\Site;
use App\Domain\Devices\Services\DeviceStatusResolver;
use App\Domain\Shared\Services\PortableSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Devices\StoreDeviceRequest;
use App\Http\Requests\Devices\UpdateDeviceRequest;
use App\Http\Resources\Devices\DeviceResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * spec section 6 — device registry web UI. Ownership boundary for this
 * coordination pass: Devices WEB UI only. The machine-facing
 * device-connector API (routes/modules/api-devices.php,
 * App\Http\Controllers\Api\V1\Devices\*) is a separate, parallel piece of
 * work and is never touched from here.
 */
class DeviceController extends Controller
{
    use AuthorizesRequests;

    private const PER_PAGE = 20;

    public function index(Request $request, DeviceStatusResolver $statusResolver): Response
    {
        $this->authorize('viewAny', Device::class);

        $search = trim((string) $request->query('search', ''));
        $statusFilter = $request->query('status');
        $siteId = $request->query('site_id');
        $page = max(1, (int) $request->query('page', 1));

        // `status` shown to operators is heartbeat-derived
        // (DeviceStatusResolver), not the stored column, so filtering by it
        // happens after resolving rather than in SQL. This is a deliberate,
        // small-scale choice: the spec's own stated pilot volume is at most
        // ~50 readers per organization, so resolving the whole set in PHP
        // before paginating stays correct and fast; it would need to move
        // to a SQL-computable status column before that assumption changes.
        $rows = Device::query()
            ->with('site')
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $like = "%{$search}%";
                PortableSearch::where($query, 'name', $like);
                PortableSearch::orWhere($query, 'serial_number', $like);
                PortableSearch::orWhere($query, 'device_identifier', $like);
            }))
            ->when($siteId, fn ($query) => $query->where('site_id', $siteId))
            ->orderBy('name')
            ->get()
            ->map(fn (Device $device) => (new DeviceResource($device))->resolve($request) + [
                'resolved_status' => $statusResolver->statusFor($device),
            ]);

        if ($statusFilter) {
            $rows = $rows->where('resolved_status', $statusFilter)->values();
        }

        $devices = new LengthAwarePaginator(
            $rows->forPage($page, self::PER_PAGE)->values(),
            $rows->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return Inertia::render('Devices/Index', [
            'devices' => $devices,
            'filters' => ['search' => $search, 'status' => $statusFilter, 'site_id' => $siteId],
            'sites' => Site::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canManage' => $request->user()->can('create', Device::class),
            'isSimulatorMode' => config('devices.adapter') === 'simulator',
            'biostarReadOnly' => $this->isBioStarReadOnly(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Device::class);

        return Inertia::render('Devices/Create', [
            'sites' => Site::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreDeviceRequest $request, RegisterDeviceAction $action): RedirectResponse
    {
        $this->authorize('create', Device::class);

        $device = $action->execute($request->deviceData(), $request->user());

        return to_route('devices.show', $device)->with('toast', [
            'type' => 'success',
            'message' => 'მოწყობილობა დარეგისტრირდა.',
        ]);
    }

    public function show(Request $request, Device $device, DeviceStatusResolver $statusResolver): Response
    {
        $this->authorize('view', $device);

        $device->load(['site', 'capabilities']);

        $commands = $device->syncCommands()
            ->orderByDesc('command_version')
            ->limit(50)
            ->get()
            ->map(fn ($command) => [
                'id' => $command->id,
                'command_type' => $command->command_type,
                'target_entity_type' => $command->target_entity_type,
                'target_entity_id' => $command->target_entity_id,
                'command_version' => $command->command_version,
                'status' => $command->status,
                'attempts' => $command->attempts,
                'last_error' => $command->last_error,
                'acknowledged_at' => $command->acknowledged_at?->toIso8601String(),
            ]);

        return Inertia::render('Devices/Show', [
            'device' => (new DeviceResource($device))->resolve($request),
            'resolvedStatus' => $statusResolver->statusFor($device),
            'capabilities' => $device->capabilities->map(fn (DeviceCapability $capability): array => [
                'key' => $capability->capability_key,
                'value' => $capability->capability_value,
                'read_at' => $capability->read_at->toIso8601String(),
            ]),
            'syncCommands' => $commands,
            'importHealth' => $this->importHealthFor($device),
            'isSimulatorMode' => config('devices.adapter') === 'simulator',
            'biostarReadOnly' => $this->isBioStarReadOnly(),
            'canOperateSimulator' => $request->user()->can('operateSimulator', $device),
            'canEdit' => $request->user()->can('update', $device),
        ]);
    }

    /**
     * BIO-03 (partial slice — see docs/claude-overnight-progress.md for what
     * is and isn't covered): the admin-visible event-import health the
     * ticket calls for — last checkpoint position, last confirmed import,
     * open data-gap/out-of-order anomalies, and stuck sync commands — built
     * entirely from data this app already durably records
     * (App\Domain\Devices\Models\DeviceCheckpoint,
     * App\Domain\Attendance\Models\AttendanceAnomaly,
     * App\Domain\Devices\Models\DeviceSyncCommand). Does NOT attempt to
     * confirm or change the adapter's own pagination/query strategy against
     * BioStar's real API — that half of BIO-03 needs live API access this
     * session does not have, and is recorded as a deferred slice rather than
     * guessed at.
     *
     * @return array<string, mixed>
     */
    private function importHealthFor(Device $device): array
    {
        $checkpoint = DeviceCheckpoint::query()->where('device_id', $device->id)->first();

        $openAnomalyCounts = AttendanceAnomaly::query()
            ->where('device_id', $device->id)
            ->whereNull('resolved_at')
            ->whereIn('anomaly_type', ['data_gap', 'out_of_order_events', 'clock_drift'])
            ->selectRaw('anomaly_type, count(*) as total')
            ->groupBy('anomaly_type')
            ->pluck('total', 'anomaly_type');

        $commandBacklog = DeviceSyncCommand::query()
            ->where('device_id', $device->id)
            ->whereIn('status', ['pending', 'retry', 'failed', 'dead_letter'])
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'checkpoint' => $checkpoint === null ? null : [
                'stream_epoch' => $checkpoint->stream_epoch,
                'last_native_event_id' => $checkpoint->last_native_event_id,
                'last_confirmed_at' => $checkpoint->last_confirmed_at?->toIso8601String(),
            ],
            'open_anomalies' => [
                'data_gap' => (int) ($openAnomalyCounts['data_gap'] ?? 0),
                'out_of_order_events' => (int) ($openAnomalyCounts['out_of_order_events'] ?? 0),
                'clock_drift' => (int) ($openAnomalyCounts['clock_drift'] ?? 0),
            ],
            'command_backlog' => [
                'pending' => (int) ($commandBacklog['pending'] ?? 0),
                'retry' => (int) ($commandBacklog['retry'] ?? 0),
                'failed' => (int) ($commandBacklog['failed'] ?? 0),
                'dead_letter' => (int) ($commandBacklog['dead_letter'] ?? 0),
            ],
        ];
    }

    /**
     * BIO-01: true whenever a real BioStar adapter is configured but write
     * dispatch has not been explicitly turned on — the UI state driving
     * App\Http\Controllers\Api\V1\Devices\ConnectorCommandController's own
     * server-side withholding of write commands from the connector.
     */
    private function isBioStarReadOnly(): bool
    {
        return config('devices.adapter') === 'suprema'
            && ! config('devices.biostar_write_dispatch_enabled');
    }

    public function edit(Request $request, Device $device): Response
    {
        $this->authorize('update', $device);
        $device->load('site');

        return Inertia::render('Devices/Edit', [
            'device' => (new DeviceResource($device))->resolve($request),
            'sites' => Site::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateDeviceRequest $request, Device $device, UpdateDeviceAction $action): RedirectResponse
    {
        $this->authorize('update', $device);
        $action->execute($device, $request->deviceData(), $request->user());

        return to_route('devices.show', $device)->with('toast', [
            'type' => 'success',
            'message' => 'მოწყობილობის მონაცემები განახლდა.',
        ]);
    }
}
