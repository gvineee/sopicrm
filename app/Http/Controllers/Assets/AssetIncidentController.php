<?php

namespace App\Http\Controllers\Assets;

use App\Domain\Assets\Actions\DecideAssetIncidentAction;
use App\Domain\Assets\Actions\ReportAssetIncidentAction;
use App\Domain\Assets\Exceptions\AssetDomainException;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetIncident;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assets\DecideAssetIncidentRequest;
use App\Http\Requests\Assets\ReportAssetIncidentRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;

class AssetIncidentController extends Controller
{
    public function store(ReportAssetIncidentRequest $request, Asset $asset, ReportAssetIncidentAction $action): RedirectResponse
    {
        $this->authorize('report', AssetIncident::class);

        $action->execute($asset, $request->incidentData(), $request->user());

        return Redirect::route('assets.show', $asset)->with('success', 'ინციდენტის ანგარიში გაიგზავნა.');
    }

    public function decide(DecideAssetIncidentRequest $request, AssetIncident $incident, DecideAssetIncidentAction $action): RedirectResponse
    {
        $this->authorize('decide', $incident);

        try {
            $action->execute(
                $incident,
                (int) $request->validated('target_version'),
                $request->validated('decision'),
                $request->user(),
                $request->validated('reason'),
            );
        } catch (AssetDomainException $exception) {
            return back()->withErrors(['incident' => $exception->getMessage()])->withInput();
        }

        return Redirect::route('assets.show', $incident->asset_id)->with('success', 'ინციდენტის გადაწყვეტილება დაფიქსირდა.');
    }
}
