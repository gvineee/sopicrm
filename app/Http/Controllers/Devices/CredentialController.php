<?php

namespace App\Http\Controllers\Devices;

use App\Domain\Devices\Actions\IssueCredentialAction;
use App\Domain\Devices\Actions\ReissueCredentialAction;
use App\Domain\Devices\Actions\RevokeCredentialAction;
use App\Domain\Devices\Models\Credential;
use App\Domain\Devices\Models\CredentialAssignment;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\Site;
use App\Domain\Devices\Services\CardIdentifierNormalizer;
use App\Domain\Devices\Services\DeviceDesiredStateResolver;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Services\PortableSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Devices\IssueCredentialRequest;
use App\Http\Requests\Devices\ReissueCredentialRequest;
use App\Http\Requests\Devices\RevokeCredentialRequest;
use App\Http\Resources\Devices\CredentialResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * spec section 6 credential issue/reissue/revoke web UI. Devices WEB UI
 * ownership only — see App\Http\Controllers\Devices\DeviceController's
 * header for the exact boundary with the parallel backend/API work.
 */
class CredentialController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request, DeviceDesiredStateResolver $desiredState): Response
    {
        $this->authorize('viewAny', Credential::class);

        $search = trim((string) $request->query('search', ''));

        $credentials = Credential::query()
            ->with(['assignments' => fn ($query) => $query->orderByDesc('valid_from')->with('employee')])
            ->when($search !== '', fn ($query) => PortableSearch::where($query, 'canonical_identifier', "%{$search}%"))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $allDevices = Device::query()->orderBy('serial_number')->get();

        $credentials->through(function (Credential $credential) use ($request, $desiredState, $allDevices) {
            $data = (new CredentialResource($credential))->resolve($request);
            /** @var CredentialAssignment|null $assignment */
            $assignment = $credential->assignments->firstWhere('status', 'active')
                ?? $credential->assignments->first();

            // A revoked credential has no active assignment, but its latest
            // revoked assignment is exactly the target whose per-reader
            // acknowledgement operators must still see.
            $data['device_sync'] = $assignment === null ? [] : $allDevices
                ->filter(fn (Device $device) => empty($assignment->site_scope) || in_array($device->site_id, $assignment->site_scope, true))
                ->map(fn (Device $device) => [
                    'device_id' => $device->id,
                    'device_serial' => $device->serial_number,
                    ...$desiredState->stateFor($device, $assignment),
                ])
                ->values();

            return $data;
        });

        return Inertia::render('Devices/Credentials/Index', [
            'credentials' => $credentials,
            'filters' => ['search' => $search],
            'employees' => Employee::query()->where('status', 'active')->orderBy('last_name')->get(['id', 'first_name', 'last_name']),
            'sites' => Site::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canManage' => $request->user()->can('manage', Credential::class),
            'isSimulatorMode' => config('devices.adapter') === 'simulator',
            'biostarReadOnly' => config('devices.adapter') === 'suprema'
                && ! config('devices.biostar_write_dispatch_enabled'),
        ]);
    }

    public function store(IssueCredentialRequest $request, CardIdentifierNormalizer $normalizer, IssueCredentialAction $action): RedirectResponse
    {
        $this->authorize('manage', Credential::class);

        $bitLength = $request->validated('bit_length') ? (int) $request->validated('bit_length') : null;

        $cardNumber = $request->validated('input_format') === 'hex'
            ? $normalizer->fromHex((string) $request->validated('card_value'), (string) $request->validated('card_type'), $bitLength)
            : $normalizer->fromDecimal((string) $request->validated('card_value'), (string) $request->validated('card_type'), $bitLength ?? 32);

        $action->execute(
            cardNumber: $cardNumber,
            employeeId: (string) $request->validated('employee_id'),
            validFrom: $request->validated('valid_from') ?: null,
            validTo: $request->validated('valid_to') ?: null,
            siteIds: $request->validated('site_ids') ?: null,
            actor: $request->user(),
        );

        return to_route('credentials.index')->with('toast', [
            'type' => 'success',
            'message' => 'ბარათი გაიცა.',
        ]);
    }

    public function reissue(ReissueCredentialRequest $request, Credential $credential, ReissueCredentialAction $action): RedirectResponse
    {
        $this->authorize('manage', Credential::class);

        $action->execute(
            credential: $credential,
            newEmployeeId: (string) $request->validated('employee_id'),
            validFrom: $request->validated('valid_from') ?: null,
            validTo: $request->validated('valid_to') ?: null,
            siteIds: $request->validated('site_ids') ?: null,
            reason: (string) $request->validated('reason'),
            actor: $request->user(),
        );

        return to_route('credentials.index')->with('toast', [
            'type' => 'success',
            'message' => 'ბარათი ხელახლა გაიცა.',
        ]);
    }

    public function revoke(RevokeCredentialRequest $request, CredentialAssignment $assignment, RevokeCredentialAction $action): RedirectResponse
    {
        $this->authorize('manage', Credential::class);

        $action->execute($assignment, (string) $request->validated('reason'), $request->user());

        return to_route('credentials.index')->with('toast', [
            'type' => 'success',
            'message' => 'ბარათი გაუქმდა.',
        ]);
    }
}
