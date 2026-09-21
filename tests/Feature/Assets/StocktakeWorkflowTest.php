<?php

use App\Domain\Assets\Actions\ApproveStocktakeVarianceAction;
use App\Domain\Assets\Actions\CompleteStocktakeAction;
use App\Domain\Assets\Actions\ScanStocktakeLineAction;
use App\Domain\Assets\Actions\StartStocktakeAction;
use App\Domain\Assets\Exceptions\InvalidCustodyStateException;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetLocation;
use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Models\Site;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

pest()->group('assets');

/**
 * Stocktake (ASSETS-01 deferred remainder), spec 9.6: "სკანირება პირდაპირ
 * არ ცვლის საბუღალტრო ნაშთს" — a raw count is an observation only, until
 * a separate, explicitly-approved adjustment changes the ledger.
 */
beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->counter = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->counter->assignRole('warehouse_keeper');

    $this->approver = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->approver->assignRole('owner');

    $this->plainEmployee = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->plainEmployee->assignRole('employee');

    $this->site = Site::factory()->create(['organization_id' => $this->organization->id]);
});

function siteAsset(string $organizationId, string $siteId, float $quantity = 1.0, string $trackingType = 'quantity'): Asset
{
    $asset = Asset::factory()->create([
        'organization_id' => $organizationId,
        'tracking_type' => $trackingType,
        'quantity_on_hand' => $quantity,
    ]);

    AssetLocation::factory()->create([
        'organization_id' => $organizationId,
        'asset_id' => $asset->id,
        'locatable_type' => 'site',
        'locatable_id' => $siteId,
        'is_current' => true,
    ]);

    return $asset;
}

test('starting a stocktake snapshots expected quantities for every in-scope asset', function () {
    $assetA = siteAsset($this->organization->id, $this->site->id, 5.0);
    $assetB = siteAsset($this->organization->id, $this->site->id, 2.0);
    // Not in scope — a different site.
    $otherSite = Site::factory()->create(['organization_id' => $this->organization->id]);
    siteAsset($this->organization->id, $otherSite->id, 9.0);

    $stocktake = app(StartStocktakeAction::class)->execute('site', $this->site->id, $this->counter);

    expect($stocktake->lines)->toHaveCount(2);
    expect($stocktake->expected_snapshot)->toEqual([$assetA->id => 5.0, $assetB->id => 2.0]);
    $lineA = $stocktake->lines->firstWhere('asset_id', $assetA->id);
    expect((float) $lineA->expected_quantity)->toBe(5.0)
        ->and($lineA->counted_quantity)->toBeNull();
});

test('a raw count NEVER changes quantity_on_hand before approval — the hard rule', function () {
    $asset = siteAsset($this->organization->id, $this->site->id, 5.0);
    $stocktake = app(StartStocktakeAction::class)->execute('site', $this->site->id, $this->counter);
    $line = $stocktake->lines->first();

    app(ScanStocktakeLineAction::class)->execute($line, 3.0, $this->counter);

    expect($asset->fresh()->quantity_on_hand)->toEqual(5.0, 'recording a count must never touch the ledger balance');
    expect($line->fresh()->counted_quantity)->toEqual(3.0);
    expect($line->fresh()->variance_approved_adjustment_id)->toBeNull();
});

test('a matching count needs no approval and the stocktake can complete', function () {
    siteAsset($this->organization->id, $this->site->id, 5.0);
    $stocktake = app(StartStocktakeAction::class)->execute('site', $this->site->id, $this->counter);
    $line = $stocktake->lines->first();

    app(ScanStocktakeLineAction::class)->execute($line, 5.0, $this->counter);

    $completed = app(CompleteStocktakeAction::class)->execute($stocktake, $this->approver);

    expect($completed->status)->toBe('completed');
});

test('a real variance blocks completion until approved', function () {
    siteAsset($this->organization->id, $this->site->id, 5.0);
    $stocktake = app(StartStocktakeAction::class)->execute('site', $this->site->id, $this->counter);
    $line = $stocktake->lines->first();

    app(ScanStocktakeLineAction::class)->execute($line, 3.0, $this->counter);

    expect(fn () => app(CompleteStocktakeAction::class)->execute($stocktake->fresh(), $this->approver))
        ->toThrow(InvalidCustodyStateException::class);
});

test('approving a variance creates the real adjustment record and updates the ledger', function () {
    $asset = siteAsset($this->organization->id, $this->site->id, 5.0);
    $stocktake = app(StartStocktakeAction::class)->execute('site', $this->site->id, $this->counter);
    $line = $stocktake->lines->first();

    app(ScanStocktakeLineAction::class)->execute($line, 3.0, $this->counter);
    $adjustment = app(ApproveStocktakeVarianceAction::class)->execute($line->fresh(), 'quantity_correction', $this->approver, 'ფაქტობრივი დათვლა');

    expect($asset->fresh()->quantity_on_hand)->toEqual(3.0)
        ->and($adjustment->quantity_before)->toEqual(5.0)
        ->and($adjustment->quantity_after)->toEqual(3.0)
        ->and($adjustment->approved_by_user_id)->toBe($this->approver->id);

    $freshLine = $line->fresh();
    expect($freshLine->variance_approved_adjustment_id)->toBe($adjustment->id);

    $completed = app(CompleteStocktakeAction::class)->execute($stocktake->fresh(), $this->approver);
    expect($completed->status)->toBe('completed');
});

test('marking an individually-tracked asset lost writes off its condition', function () {
    $asset = Asset::factory()->create([
        'organization_id' => $this->organization->id,
        'tracking_type' => 'individual',
        'condition' => 'good',
    ]);
    AssetLocation::factory()->create([
        'organization_id' => $this->organization->id,
        'asset_id' => $asset->id,
        'locatable_type' => 'site',
        'locatable_id' => $this->site->id,
        'is_current' => true,
    ]);

    $stocktake = app(StartStocktakeAction::class)->execute('site', $this->site->id, $this->counter);
    $line = $stocktake->lines->first();

    app(ScanStocktakeLineAction::class)->execute($line, 0.0, $this->counter);
    app(ApproveStocktakeVarianceAction::class)->execute($line->fresh(), 'marked_lost', $this->approver);

    expect($asset->fresh()->condition)->toBe('written_off');
});

test('a recount creates a new line referencing the original rather than overwriting it', function () {
    siteAsset($this->organization->id, $this->site->id, 5.0);
    $stocktake = app(StartStocktakeAction::class)->execute('site', $this->site->id, $this->counter);
    $line = $stocktake->lines->first();

    app(ScanStocktakeLineAction::class)->execute($line, 3.0, $this->counter);
    $recount = app(ScanStocktakeLineAction::class)->execute($line->fresh(), 5.0, $this->counter, true);

    expect($recount->id)->not->toBe($line->id)
        ->and($recount->recount_of_line_id)->toBe($line->id)
        ->and($recount->counted_quantity)->toEqual(5.0);
    // The original line's own count is untouched — immutable history.
    expect($line->fresh()->counted_quantity)->toEqual(3.0);
});

test('a plain employee cannot start, count, or approve a stocktake — denied direct request', function () {
    siteAsset($this->organization->id, $this->site->id, 5.0);

    $this->actingAs($this->plainEmployee)
        ->post(route('assets.stocktakes.store'), ['scope_type' => 'site', 'scope_id' => $this->site->id])
        ->assertForbidden();

    // The preceding HTTP request's own lifecycle already reset
    // CurrentOrganization on termination — re-establish it before calling
    // an Action directly again outside any request context.
    CurrentOrganization::set($this->organization->id);
    $stocktake = app(StartStocktakeAction::class)->execute('site', $this->site->id, $this->counter);
    $line = $stocktake->lines->first();

    $this->actingAs($this->plainEmployee)
        ->post(route('assets.stocktakes.scan', [$stocktake, $line]), ['counted_quantity' => 5])
        ->assertForbidden();

    CurrentOrganization::set($this->organization->id);
    app(ScanStocktakeLineAction::class)->execute($line, 3.0, $this->counter);

    $this->actingAs($this->plainEmployee)
        ->post(route('assets.stocktakes.approve-variance', [$stocktake, $line]), ['adjustment_type' => 'quantity_correction'])
        ->assertForbidden();
});

test('a counter without the approve permission cannot resolve a variance via a direct request', function () {
    siteAsset($this->organization->id, $this->site->id, 5.0);
    $stocktake = app(StartStocktakeAction::class)->execute('site', $this->site->id, $this->counter);
    $line = $stocktake->lines->first();

    app(ScanStocktakeLineAction::class)->execute($line, 3.0, $this->counter);

    // warehouse_keeper holds both perform+approve in this seed's grants —
    // use a project_manager (perform only, per this ticket's own seeder
    // choice) to prove the two abilities are independently enforced.
    $counterOnly = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $counterOnly->assignRole('project_manager');

    $this->actingAs($counterOnly)
        ->post(route('assets.stocktakes.approve-variance', [$stocktake, $line]), ['adjustment_type' => 'quantity_correction'])
        ->assertForbidden();
});
