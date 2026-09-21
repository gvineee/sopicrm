<?php

namespace App\Http\Controllers\Assets;

use App\Domain\Assets\Actions\ApproveStocktakeVarianceAction;
use App\Domain\Assets\Actions\CompleteStocktakeAction;
use App\Domain\Assets\Actions\ScanStocktakeLineAction;
use App\Domain\Assets\Actions\StartStocktakeAction;
use App\Domain\Assets\Exceptions\AssetDomainException;
use App\Domain\Assets\Models\Stocktake;
use App\Domain\Assets\Models\StocktakeLine;
use App\Domain\Devices\Models\Site;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assets\ApproveStocktakeVarianceRequest;
use App\Http\Requests\Assets\ScanStocktakeLineRequest;
use App\Http\Requests\Assets\StartStocktakeRequest;
use App\Http\Resources\Assets\StocktakeResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stocktake (ASSETS-01 deferred remainder, spec 9.6). Thin controller: every
 * mutating action re-checks the real Policy, a raw count is never itself
 * the ledger — only ApproveStocktakeVarianceAction is.
 */
class StocktakeController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Stocktake::class);

        $stocktakes = Stocktake::query()
            ->with('performedBy')
            ->orderByDesc('session_started_at')
            ->paginate(20);

        return Inertia::render('Assets/Stocktakes/Index', [
            'stocktakes' => StocktakeResource::collection($stocktakes->items()),
            'meta' => ['page' => $stocktakes->currentPage(), 'perPage' => $stocktakes->perPage(), 'total' => $stocktakes->total()],
            'sites' => Site::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canPerform' => $request->user()?->can('perform', Stocktake::class) ?? false,
        ]);
    }

    public function store(StartStocktakeRequest $request, StartStocktakeAction $action): RedirectResponse
    {
        $this->authorize('perform', Stocktake::class);

        $stocktake = $action->execute(
            $request->validated('scope_type'),
            $request->validated('scope_id'),
            $request->user(),
        );

        return Redirect::route('assets.stocktakes.show', $stocktake)->with('success', 'ინვენტარიზაცია დაიწყო.');
    }

    public function show(Request $request, Stocktake $stocktake): Response
    {
        $this->authorize('view', $stocktake);

        $stocktake->load(['lines.asset', 'performedBy']);

        return Inertia::render('Assets/Stocktakes/Show', [
            'stocktake' => (new StocktakeResource($stocktake))->resolve(),
            'can' => [
                'count' => $request->user()?->can('count', $stocktake) ?? false,
                'approve' => $request->user()?->can('approve', $stocktake) ?? false,
                'complete' => $request->user()?->can('complete', $stocktake) ?? false,
            ],
        ]);
    }

    public function scan(
        ScanStocktakeLineRequest $request,
        Stocktake $stocktake,
        StocktakeLine $line,
        ScanStocktakeLineAction $action,
    ): RedirectResponse {
        $this->authorize('count', $stocktake);
        abort_unless($line->stocktake_id === $stocktake->id, 404);

        try {
            $action->execute($line, (float) $request->validated('counted_quantity'), $request->user(), (bool) $request->validated('recount', false));
        } catch (AssetDomainException $exception) {
            return back()->withErrors(['count' => $exception->getMessage()]);
        }

        return Redirect::route('assets.stocktakes.show', $stocktake)->with('success', 'დათვლა შენახულია.');
    }

    public function approveVariance(
        ApproveStocktakeVarianceRequest $request,
        Stocktake $stocktake,
        StocktakeLine $line,
        ApproveStocktakeVarianceAction $action,
    ): RedirectResponse {
        $this->authorize('approve', $stocktake);
        abort_unless($line->stocktake_id === $stocktake->id, 404);

        try {
            $action->execute($line, $request->validated('adjustment_type'), $request->user(), $request->validated('notes'));
        } catch (AssetDomainException $exception) {
            return back()->withErrors(['variance' => $exception->getMessage()]);
        }

        return Redirect::route('assets.stocktakes.show', $stocktake)->with('success', 'ვარიაცია დამტკიცდა.');
    }

    public function complete(Request $request, Stocktake $stocktake, CompleteStocktakeAction $action): RedirectResponse
    {
        $this->authorize('complete', $stocktake);

        try {
            $action->execute($stocktake, $request->user());
        } catch (AssetDomainException $exception) {
            return back()->withErrors(['stocktake' => $exception->getMessage()]);
        }

        return Redirect::route('assets.stocktakes.index')->with('success', 'ინვენტარიზაცია დასრულდა.');
    }
}
