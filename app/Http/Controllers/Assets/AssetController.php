<?php

namespace App\Http\Controllers\Assets;

use App\Domain\Assets\Actions\RegisterAssetAction;
use App\Domain\Assets\Exceptions\AssetDomainException;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetIncident;
use App\Domain\Assets\Models\CustodyTransaction;
use App\Domain\Assets\Models\Maintenance;
use App\Domain\Assets\Models\StocktakeLine;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Services\PortableSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assets\StoreAssetRequest;
use App\Http\Resources\Assets\AssetIncidentResource;
use App\Http\Resources\Assets\AssetResource;
use App\Http\Resources\Assets\CustodyTransactionResource;
use App\Http\Resources\Assets\MaintenanceResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ASSETS-01. Thin controller: validate -> Domain Action -> Inertia, every
 * Action re-checked by the real Policy (a hidden menu item is never itself
 * the authorization boundary).
 */
class AssetController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Asset::class);

        $search = $request->query('search');
        $trackingType = $request->query('tracking_type');
        $condition = $request->query('condition');

        $assets = Asset::query()
            ->with(['activeCustody', 'currentLocation'])
            ->when($search, function ($query) use ($search) {
                $term = "%{$search}%";
                $query->where(function ($inner) use ($term) {
                    PortableSearch::where($inner, 'name', $term);
                    PortableSearch::orWhere($inner, 'inventory_code', $term);
                    PortableSearch::orWhere($inner, 'serial_number', $term);
                });
            })
            ->when($trackingType, fn ($query) => $query->where('tracking_type', $trackingType))
            ->when($condition, fn ($query) => $query->where('condition', $condition))
            ->orderBy('name')
            ->paginate((int) $request->query('per_page', 20))
            ->withQueryString();

        return Inertia::render('Assets/Index', [
            'assets' => AssetResource::collection($assets->items()),
            'meta' => [
                'page' => $assets->currentPage(),
                'perPage' => $assets->perPage(),
                'total' => $assets->total(),
            ],
            'filters' => ['search' => $search, 'tracking_type' => $trackingType, 'condition' => $condition],
            'canCreate' => $request->user()?->can('create', Asset::class) ?? false,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Asset::class);

        return Inertia::render('Assets/Create', [
            'employees' => Employee::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
        ]);
    }

    public function store(StoreAssetRequest $request, RegisterAssetAction $action): RedirectResponse
    {
        $this->authorize('create', Asset::class);

        try {
            $asset = $action->execute($request->assetData(), $request->user());
        } catch (AssetDomainException $exception) {
            return back()->withErrors(['inventory_code' => $exception->getMessage()])->withInput();
        }

        return Redirect::route('assets.show', $asset)->with('success', 'აქტივი დარეგისტრირდა.');
    }

    public function show(Request $request, Asset $asset): Response
    {
        $this->authorize('view', $asset);

        $asset->load(['activeCustody', 'currentLocation']);

        $custodyHistory = CustodyTransaction::query()
            ->whereHas('lines', fn ($query) => $query->where('asset_id', $asset->id))
            ->with(['lines' => fn ($query) => $query->where('asset_id', $asset->id), 'receivingEmployee'])
            ->orderByDesc('occurred_at')
            ->get();

        $activeTransaction = $asset->activeCustody?->current_custody_transaction_id !== null
            ? CustodyTransaction::query()->with(['lines.asset', 'receivingEmployee'])->find($asset->activeCustody->current_custody_transaction_id)
            : null;

        $incidents = AssetIncident::query()
            ->where('asset_id', $asset->id)
            ->with(['reportedBy', 'reviewedBy'])
            ->orderByDesc('occurred_at')
            ->get();

        $maintenanceRecords = Maintenance::query()
            ->where('asset_id', $asset->id)
            ->orderByDesc('created_at')
            ->get();

        // REQ-AST-10 ("full asset history"): the stocktake half of this
        // asset's real timeline — every count/variance-resolution it was
        // ever part of, alongside the custody/incident/maintenance history
        // already loaded above.
        $stocktakeLines = StocktakeLine::query()
            ->where('asset_id', $asset->id)
            ->with('stocktake')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Assets/Show', [
            'asset' => (new AssetResource($asset))->resolve(),
            'activeTransaction' => $activeTransaction === null ? null : (new CustodyTransactionResource($activeTransaction))->resolve(),
            'custodyHistory' => CustodyTransactionResource::collection($custodyHistory)->resolve(),
            'incidents' => AssetIncidentResource::collection($incidents)->resolve(),
            'maintenanceRecords' => MaintenanceResource::collection($maintenanceRecords)->resolve(),
            'stocktakeLines' => $stocktakeLines->map(fn ($line) => [
                'id' => $line->id,
                'stocktake_id' => $line->stocktake_id,
                'stocktake_scope_type' => $line->stocktake?->scope_type,
                'expected_quantity' => (float) $line->expected_quantity,
                'counted_quantity' => $line->counted_quantity !== null ? (float) $line->counted_quantity : null,
                'has_approved_adjustment' => $line->variance_approved_adjustment_id !== null,
                'created_at' => $line->created_at?->toIso8601String(),
            ])->values(),
            'employees' => Employee::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'can' => [
                'manageCustody' => $request->user()?->can('assets.custody.manage') ?? false,
                'reportIncident' => $request->user()?->can('report', AssetIncident::class) ?? false,
                'decideIncident' => $request->user()?->can('assets.incidents.decide') ?? false,
                'manageMaintenance' => $request->user()?->can('create', Maintenance::class) ?? false,
            ],
        ]);
    }

    /**
     * REQ-AST-02: resolves a scanned QR token to its asset's real Show page
     * — the token itself grants no access (spec's own explicit rule); the
     * real `AssetPolicy::view` check runs here exactly as it would for any
     * other route reaching this asset. A physical asset's printed QR code
     * encodes this route's own full URL directly (`/assets/qr/{token}`), so
     * "scanning" is a plain camera-app QR-to-URL open on any modern phone —
     * no in-app QR-decoding library is needed or was added (this codebase's
     * established preference for building a primitive directly over adding
     * a dependency, e.g. DEC-047's identical reasoning for offlineQueue).
     * An unknown token and a token the caller cannot view both 404
     * identically — never a 403, which would itself confirm the token was
     * real to an unauthorized prober.
     */
    public function scanQr(Request $request, string $qrToken): RedirectResponse
    {
        $asset = Asset::query()->where('qr_token', $qrToken)->first();

        if ($asset === null || $request->user()?->cannot('view', $asset)) {
            abort(404);
        }

        return Redirect::route('assets.show', $asset);
    }
}
