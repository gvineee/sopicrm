<?php

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetActiveCustody;
use App\Domain\Assets\Models\AssetIncident;
use App\Domain\Assets\Models\CustodyLine;
use App\Domain\Assets\Models\CustodyTransaction;
use App\Domain\Assets\Models\Maintenance;
use App\Domain\Assets\Models\StocktakeAdjustment;
use App\Domain\Assets\Models\StocktakeLine;
use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

pest()->group('assets');

/**
 * REQ-AST-10: read-only reports over already-correct custody/incident/
 * maintenance/stocktake data. Fixtures are built directly against the real
 * schema (not through the full HTTP issue flow) since these tests verify
 * the REPORT's own query correctness, not the domain Actions that produced
 * the underlying rows — those are already covered by
 * AssetCustodyWorkflowTest/StocktakeWorkflowTest.
 */
beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->owner = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->owner->assignRole('owner');

    $this->plainEmployeeUser = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->plainEmployeeUser->assignRole('employee');

    $this->employeeA = Employee::factory()->create(['organization_id' => $this->organization->id]);
    $this->employeeB = Employee::factory()->create(['organization_id' => $this->organization->id]);
    $this->projectA = Project::factory()->create(['organization_id' => $this->organization->id]);
    $this->projectB = Project::factory()->create(['organization_id' => $this->organization->id]);
});

function issuedAsset(string $organizationId, array $transactionOverrides = []): array
{
    $asset = Asset::factory()->create(['organization_id' => $organizationId]);

    $transaction = CustodyTransaction::factory()->create([
        'organization_id' => $organizationId,
        'type' => 'issue',
        'status' => 'issued',
    ] + $transactionOverrides);

    CustodyLine::factory()->create([
        'organization_id' => $organizationId,
        'custody_transaction_id' => $transaction->id,
        'asset_id' => $asset->id,
    ]);

    AssetActiveCustody::factory()->create([
        'organization_id' => $organizationId,
        'asset_id' => $asset->id,
        'status' => 'issued',
        'current_custody_transaction_id' => $transaction->id,
    ]);

    return [$asset, $transaction];
}

test('who-holds-what shows every currently issued asset with its real holder', function () {
    [$asset] = issuedAsset($this->organization->id, ['receiving_employee_id' => $this->employeeA->id]);

    $response = $this->actingAs($this->owner)->get('/assets/reports?report=who-holds-what');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Assets/Reports/Index')
        ->where('report', 'who-holds-what')
        ->has('rows', 1)
        ->where('rows.0.asset_id', $asset->id)
        ->where('rows.0.holder_name', trim($this->employeeA->first_name.' '.$this->employeeA->last_name)));
});

test('overdue returns only shows a transaction whose expected_return_at has genuinely passed', function () {
    issuedAsset($this->organization->id, [
        'receiving_employee_id' => $this->employeeA->id,
        'expected_return_at' => Carbon::now()->subDays(3),
    ]);
    issuedAsset($this->organization->id, [
        'receiving_employee_id' => $this->employeeB->id,
        'expected_return_at' => Carbon::now()->addDays(3),
    ]);

    $response = $this->actingAs($this->owner)->get('/assets/reports?report=overdue-returns');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.days_overdue', 3));
});

test('per-project allocation is correctly scoped to the requested project only', function () {
    [$assetA] = issuedAsset($this->organization->id, [
        'receiving_employee_id' => $this->employeeA->id,
        'project_id' => $this->projectA->id,
    ]);
    issuedAsset($this->organization->id, [
        'receiving_employee_id' => $this->employeeB->id,
        'project_id' => $this->projectB->id,
    ]);

    $response = $this->actingAs($this->owner)->get('/assets/reports?'.http_build_query([
        'report' => 'allocation',
        'project_id' => $this->projectA->id,
    ]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.asset_id', $assetA->id));
});

test('service history lists maintenance records for the correct asset', function () {
    $asset = Asset::factory()->create(['organization_id' => $this->organization->id]);
    Maintenance::factory()->create([
        'organization_id' => $this->organization->id,
        'asset_id' => $asset->id,
        'vendor' => 'Test Service Co',
        'completed_at' => now(),
    ]);

    $response = $this->actingAs($this->owner)->get('/assets/reports?report=service-history');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('rows', 1)
        ->where('rows.0.vendor', 'Test Service Co')
        ->where('rows.0.status', 'completed'));
});

test('lost assets correctly distinguishes a stocktake-caused loss from an incident write-off', function () {
    $lostByStocktake = Asset::factory()->create(['organization_id' => $this->organization->id, 'condition' => 'written_off']);
    $stocktakeLine = StocktakeLine::factory()->create(['organization_id' => $this->organization->id, 'asset_id' => $lostByStocktake->id]);
    StocktakeAdjustment::factory()->create([
        'organization_id' => $this->organization->id,
        'asset_id' => $lostByStocktake->id,
        'stocktake_line_id' => $stocktakeLine->id,
        'adjustment_type' => 'marked_lost',
        'approved_at' => now(),
    ]);

    $lostByIncident = Asset::factory()->create(['organization_id' => $this->organization->id, 'condition' => 'written_off']);
    AssetIncident::factory()->create([
        'organization_id' => $this->organization->id,
        'asset_id' => $lostByIncident->id,
        'decision' => 'write_off',
        'decided_at' => now(),
    ]);

    $response = $this->actingAs($this->owner)->get('/assets/reports?report=lost-assets');

    $response->assertOk();
    $response->assertInertia(function ($page) use ($lostByStocktake, $lostByIncident) {
        $page->component('Assets/Reports/Index')->has('rows', 2);

        $rows = collect($page->toArray()['props']['rows']);
        expect($rows->firstWhere('asset_id', $lostByStocktake->id)['cause'])->toBe('stocktake_variance');
        expect($rows->firstWhere('asset_id', $lostByIncident->id)['cause'])->toBe('incident_write_off');
    });
});

test('a user without assets.custody.view is denied the custody-based reports directly', function () {
    foreach (['who-holds-what', 'overdue-returns', 'allocation'] as $report) {
        $this->actingAs($this->plainEmployeeUser)
            ->get("/assets/reports?report={$report}")
            ->assertForbidden();
    }
});

test('a user without assets.assets.view is denied the asset-based reports directly', function () {
    foreach (['service-history', 'lost-assets'] as $report) {
        $this->actingAs($this->plainEmployeeUser)
            ->get("/assets/reports?report={$report}")
            ->assertForbidden();
    }
});

test('cross-organization data never leaks into a report', function () {
    $otherOrganization = Organization::factory()->create();
    CurrentOrganization::set($otherOrganization->id);
    $otherAsset = Asset::factory()->create(['organization_id' => $otherOrganization->id]);
    Maintenance::factory()->create(['organization_id' => $otherOrganization->id, 'asset_id' => $otherAsset->id]);
    CurrentOrganization::set($this->organization->id);

    $response = $this->actingAs($this->owner)->get('/assets/reports?report=service-history');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('rows', 0));
});
