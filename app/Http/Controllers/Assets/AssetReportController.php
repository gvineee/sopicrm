<?php

namespace App\Http\Controllers\Assets;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetActiveCustody;
use App\Domain\Assets\Models\AssetIncident;
use App\Domain\Assets\Models\CustodyLine;
use App\Domain\Assets\Models\CustodyTransaction;
use App\Domain\Assets\Models\Maintenance;
use App\Domain\Assets\Models\StocktakeAdjustment;
use App\Domain\Projects\Models\Project;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * REQ-AST-10: read-only reporting over already-correct, already-tested
 * Assets domain data (custody/incident/maintenance/stocktake). No business
 * logic lives here — every query reads rows an existing, already-shipped
 * Action already produced correctly; this controller never mutates
 * anything. Each report reuses the exact permission that already gates
 * seeing its underlying data type elsewhere in this module, never a new,
 * separately-invented permission.
 */
class AssetReportController extends Controller
{
    private const REPORTS = ['who-holds-what', 'overdue-returns', 'allocation', 'service-history', 'lost-assets'];

    public function index(Request $request): Response
    {
        $report = $request->query('report', 'who-holds-what');

        if (! in_array($report, self::REPORTS, true)) {
            throw ValidationException::withMessages(['report' => 'უცნობი რეპორტის ტიპი.']);
        }

        $this->authorize($this->abilityFor($report));

        return Inertia::render('Assets/Reports/Index', [
            'report' => $report,
            'projects' => Project::query()->orderBy('name')->get(['id', 'name']),
            'filters' => ['project_id' => $request->query('project_id')],
            'rows' => match ($report) {
                'who-holds-what' => $this->whoHoldsWhat(),
                'overdue-returns' => $this->overdueReturns(),
                'allocation' => $this->allocation($request->query('project_id')),
                'service-history' => $this->serviceHistory(),
                'lost-assets' => $this->lostAssets(),
            },
        ]);
    }

    /**
     * Only ever called after `index()` has already validated `$report`
     * against `self::REPORTS` — the `default` arm exists purely so this
     * match is exhaustive over `string`'s full type (PHPStan can't narrow
     * a plain `string` param to the 5 known literals), not because a real
     * request can actually reach it.
     */
    private function abilityFor(string $report): string
    {
        return match ($report) {
            'who-holds-what', 'overdue-returns', 'allocation' => 'assets.custody.view',
            'service-history', 'lost-assets' => 'assets.assets.view',
            default => throw new RuntimeException("Unhandled report type: {$report}"),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function whoHoldsWhat(): array
    {
        return AssetActiveCustody::query()
            ->whereIn('status', ['issued', 'awaiting_receipt'])
            ->with(['asset', 'currentCustodyTransaction.receivingEmployee'])
            ->get()
            ->map(fn (AssetActiveCustody $custody) => [
                'asset_id' => $custody->asset_id,
                'asset_name' => $custody->asset?->name,
                'inventory_code' => $custody->asset?->inventory_code,
                'status' => $custody->status,
                'holder_name' => $custody->currentCustodyTransaction?->receivingEmployee !== null
                    ? trim($custody->currentCustodyTransaction->receivingEmployee->first_name.' '.$custody->currentCustodyTransaction->receivingEmployee->last_name)
                    : null,
                'occurred_at' => $custody->currentCustodyTransaction?->occurred_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function overdueReturns(): array
    {
        $now = Carbon::now();

        return AssetActiveCustody::query()
            ->whereIn('status', ['issued', 'awaiting_receipt'])
            ->whereHas('currentCustodyTransaction', function ($query) use ($now) {
                $query->whereNotNull('expected_return_at')->where('expected_return_at', '<', $now);
            })
            ->with(['asset', 'currentCustodyTransaction.receivingEmployee'])
            ->get()
            ->map(fn (AssetActiveCustody $custody) => [
                'asset_id' => $custody->asset_id,
                'asset_name' => $custody->asset?->name,
                'inventory_code' => $custody->asset?->inventory_code,
                'holder_name' => $custody->currentCustodyTransaction?->receivingEmployee !== null
                    ? trim($custody->currentCustodyTransaction->receivingEmployee->first_name.' '.$custody->currentCustodyTransaction->receivingEmployee->last_name)
                    : null,
                'expected_return_at' => $custody->currentCustodyTransaction?->expected_return_at?->toIso8601String(),
                'days_overdue' => $custody->currentCustodyTransaction?->expected_return_at !== null
                    ? (int) $custody->currentCustodyTransaction->expected_return_at->diffInDays($now)
                    : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function allocation(?string $projectId): array
    {
        return CustodyTransaction::query()
            ->whereIn('status', ['issued', 'awaiting_receipt'])
            ->whereNotNull('project_id')
            ->when($projectId, fn ($query) => $query->where('project_id', $projectId))
            ->with(['project', 'lines.asset', 'receivingEmployee'])
            ->get()
            ->flatMap(fn (CustodyTransaction $transaction) => $transaction->lines->map(fn (CustodyLine $line) => [
                'project_id' => $transaction->project_id,
                'project_name' => $transaction->project?->name,
                'asset_id' => $line->asset_id,
                'asset_name' => $line->asset?->name,
                'inventory_code' => $line->asset?->inventory_code,
                'holder_name' => $transaction->receivingEmployee !== null
                    ? trim($transaction->receivingEmployee->first_name.' '.$transaction->receivingEmployee->last_name)
                    : null,
                'quantity' => (float) $line->quantity,
            ]))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serviceHistory(): array
    {
        return Maintenance::query()
            ->with('asset')
            ->orderByDesc('scheduled_at')
            ->get()
            ->map(fn (Maintenance $record) => [
                'id' => $record->id,
                'asset_id' => $record->asset_id,
                'asset_name' => $record->asset?->name,
                'inventory_code' => $record->asset?->inventory_code,
                'vendor' => $record->vendor,
                'scheduled_at' => $record->scheduled_at?->toIso8601String(),
                'completed_at' => $record->completed_at?->toIso8601String(),
                'actual_cost' => $record->actual_cost !== null ? (float) $record->actual_cost : null,
                'next_service_due_at' => $record->next_service_due_at?->toIso8601String(),
                'status' => $record->completed_at !== null ? 'completed' : 'scheduled',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lostAssets(): array
    {
        $assets = Asset::query()->where('condition', 'written_off')->get()->keyBy('id');

        if ($assets->isEmpty()) {
            return [];
        }

        $assetIds = $assets->keys()->all();

        /** @var array<string, StocktakeAdjustment> $lostByStocktake */
        $lostByStocktake = StocktakeAdjustment::query()
            ->whereIn('asset_id', $assetIds)
            ->where('adjustment_type', 'marked_lost')
            ->orderByDesc('approved_at')
            ->get()
            ->keyBy('asset_id')
            ->all();

        /** @var array<string, AssetIncident> $lostByIncident */
        $lostByIncident = AssetIncident::query()
            ->whereIn('asset_id', $assetIds)
            ->where('decision', 'write_off')
            ->orderByDesc('decided_at')
            ->get()
            ->keyBy('asset_id')
            ->all();

        return $assets->map(function (Asset $asset) use ($lostByStocktake, $lostByIncident) {
            $stocktakeAdjustment = $lostByStocktake[$asset->id] ?? null;
            $incident = $lostByIncident[$asset->id] ?? null;

            // Whichever record is more recent is the real, actual cause —
            // an asset could theoretically have been through both paths at
            // different times; the latest is authoritative for "why is
            // this currently written off."
            $cause = 'unknown';
            $causedAt = null;

            if ($stocktakeAdjustment !== null && ($incident === null || $stocktakeAdjustment->approved_at?->greaterThan($incident->decided_at ?? $incident->occurred_at))) {
                $cause = 'stocktake_variance';
                $causedAt = $stocktakeAdjustment->approved_at;
            } elseif ($incident !== null) {
                $cause = 'incident_write_off';
                $causedAt = $incident->decided_at ?? $incident->occurred_at;
            }

            return [
                'asset_id' => $asset->id,
                'asset_name' => $asset->name,
                'inventory_code' => $asset->inventory_code,
                'cause' => $cause,
                'caused_at' => $causedAt?->toIso8601String(),
            ];
        })->values()->all();
    }
}
