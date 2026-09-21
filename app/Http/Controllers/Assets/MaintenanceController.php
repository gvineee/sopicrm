<?php

namespace App\Http\Controllers\Assets;

use App\Domain\Assets\Actions\CompleteMaintenanceAction;
use App\Domain\Assets\Actions\ScheduleMaintenanceAction;
use App\Domain\Assets\Exceptions\AssetDomainException;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\Maintenance;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assets\CompleteMaintenanceRequest;
use App\Http\Requests\Assets\ScheduleMaintenanceRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;

class MaintenanceController extends Controller
{
    public function store(ScheduleMaintenanceRequest $request, Asset $asset, ScheduleMaintenanceAction $action): RedirectResponse
    {
        $this->authorize('create', Maintenance::class);

        $action->execute($asset, $request->validated(), $request->user());

        return Redirect::route('assets.show', $asset)->with('success', 'მომსახურება დაიგეგმა.');
    }

    public function complete(CompleteMaintenanceRequest $request, Maintenance $maintenance, CompleteMaintenanceAction $action): RedirectResponse
    {
        $this->authorize('update', $maintenance);

        try {
            $action->execute($maintenance, $request->validated(), $request->user());
        } catch (AssetDomainException $exception) {
            return back()->withErrors(['maintenance' => $exception->getMessage()]);
        }

        return Redirect::route('assets.show', $maintenance->asset_id)->with('success', 'მომსახურება დასრულებულად აღინიშნა.');
    }
}
