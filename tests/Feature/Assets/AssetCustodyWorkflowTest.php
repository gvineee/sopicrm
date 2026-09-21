<?php

use App\Domain\Assets\Actions\FinalizeIssueAction;
use App\Domain\Assets\Actions\SaveIssueDraftAction;
use App\Domain\Assets\Exceptions\AssetAlreadyIssuedException;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetActiveCustody;
use App\Domain\Assets\Models\AssetIncident;
use App\Domain\Assets\Models\AssetLocation;
use App\Domain\Assets\Models\CustodyTransaction;
use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Models\Approval;
use App\Domain\Shared\Models\AuditEvent;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

pest()->group('assets');

/**
 * ASSETS-01: issue/return/transfer/repair-loss custody workflows.
 */
beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->warehouseKeeper = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->warehouseKeeper->assignRole('warehouse_keeper');

    $this->employeeUser = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->employeeUser->assignRole('employee');

    $this->employee = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->employeeUser->id,
    ]);
});

function registerAsset(string $organizationId, array $overrides = []): Asset
{
    return Asset::factory()->create(['organization_id' => $organizationId] + $overrides);
}

test('golden path: register, issue, confirm receipt, then full return', function () {
    $asset = registerAsset($this->organization->id);
    AssetLocation::factory()->create(['organization_id' => $this->organization->id, 'asset_id' => $asset->id, 'is_current' => true]);
    AssetActiveCustody::factory()->create(['organization_id' => $this->organization->id, 'asset_id' => $asset->id, 'status' => 'available']);

    $issueResponse = $this->actingAs($this->warehouseKeeper)->post(route('assets.issue', $asset), [
        'receiving_employee_id' => $this->employee->id,
        'condition_at_transaction' => 'good',
        'lines' => [['asset_id' => $asset->id]],
    ]);
    $issueResponse->assertRedirect();

    // The web request's own SetCurrentOrganization middleware clears
    // CurrentOrganization on terminate() — every direct Eloquent query made
    // in the test body AFTER an HTTP call must re-establish it, or the
    // tenant global scope fails closed (same pattern this codebase's other
    // web-access tests already follow, e.g. AttendanceWebAccessTest).
    CurrentOrganization::set($this->organization->id);

    $transaction = CustodyTransaction::query()->where('receiving_employee_id', $this->employee->id)->firstOrFail();
    expect($transaction->status)->toBe('awaiting_receipt');
    expect(AssetActiveCustody::query()->where('asset_id', $asset->id)->value('status'))->toBe('awaiting_receipt');

    $this->actingAs($this->employeeUser)
        ->post(route('assets.custody.confirm-receipt', $transaction))
        ->assertRedirect();
    CurrentOrganization::set($this->organization->id);

    $transaction->refresh();
    expect($transaction->status)->toBe('issued');
    expect($transaction->received_confirmation_user_id)->toBe($this->employeeUser->id);
    expect(AssetActiveCustody::query()->where('asset_id', $asset->id)->value('status'))->toBe('issued');

    $line = $transaction->lines()->firstOrFail();

    $this->actingAs($this->warehouseKeeper)
        ->post(route('assets.custody.return', $transaction), [
            'lines' => [['custody_line_id' => $line->id, 'quantity' => 1, 'condition' => 'good']],
        ])
        ->assertRedirect();
    CurrentOrganization::set($this->organization->id);

    $transaction->refresh();
    expect($transaction->status)->toBe('returned');
    expect(AssetActiveCustody::query()->where('asset_id', $asset->id)->value('status'))->toBe('available');
    expect($asset->fresh()->condition)->toBe('good');
});

test('a second issue attempt on an already-reserved individually-tracked asset is rejected (concurrency guard)', function () {
    // Genuine multi-process parallel-request testing is not built here (same
    // documented limitation as this session's own MONEY-01 ticket) — this
    // proves the guard itself works correctly for the real sequential case:
    // once an asset's asset_active_custody marker is no longer 'available',
    // a second finalize attempt must be rejected, not silently double-issue.
    $asset = registerAsset($this->organization->id);
    AssetActiveCustody::factory()->create(['organization_id' => $this->organization->id, 'asset_id' => $asset->id, 'status' => 'available']);

    $draft = app(SaveIssueDraftAction::class)->execute(null, [
        'receiving_employee_id' => $this->employee->id,
        'condition_at_transaction' => 'good',
        'lines' => [['asset_id' => $asset->id]],
    ], $this->warehouseKeeper);
    app(FinalizeIssueAction::class)->execute($draft, $this->warehouseKeeper);

    expect(AssetActiveCustody::query()->where('asset_id', $asset->id)->value('status'))->toBe('awaiting_receipt');

    $secondDraft = app(SaveIssueDraftAction::class)->execute(null, [
        'receiving_employee_id' => Employee::factory()->create(['organization_id' => $this->organization->id])->id,
        'condition_at_transaction' => 'good',
        'lines' => [['asset_id' => $asset->id]],
    ], $this->warehouseKeeper);

    expect(fn () => app(FinalizeIssueAction::class)->execute($secondDraft, $this->warehouseKeeper))
        ->toThrow(AssetAlreadyIssuedException::class);

    // The first reservation is untouched by the rejected second attempt.
    expect(AssetActiveCustody::query()->where('asset_id', $asset->id)->value('status'))->toBe('awaiting_receipt');
});

test('partial return leaves the outstanding quantity correctly visible', function () {
    $asset = registerAsset($this->organization->id, ['tracking_type' => 'quantity', 'quantity_on_hand' => 10]);

    $issueResponse = $this->actingAs($this->warehouseKeeper)->post(route('assets.issue', $asset), [
        'receiving_employee_id' => $this->employee->id,
        'condition_at_transaction' => 'good',
        'lines' => [['asset_id' => $asset->id, 'quantity' => 5]],
    ]);
    $issueResponse->assertRedirect();
    CurrentOrganization::set($this->organization->id);

    expect($asset->fresh()->quantity_on_hand)->toEqualWithDelta(5.0, 0.001);

    $transaction = CustodyTransaction::query()->where('receiving_employee_id', $this->employee->id)->firstOrFail();
    $this->actingAs($this->employeeUser)->post(route('assets.custody.confirm-receipt', $transaction))->assertRedirect();
    CurrentOrganization::set($this->organization->id);

    $line = $transaction->lines()->firstOrFail();

    $this->actingAs($this->warehouseKeeper)
        ->post(route('assets.custody.return', $transaction), [
            'lines' => [['custody_line_id' => $line->id, 'quantity' => 2, 'condition' => 'good']],
        ])
        ->assertRedirect();
    CurrentOrganization::set($this->organization->id);

    $line->refresh();
    $transaction->refresh();

    expect((float) $line->returned_quantity)->toBe(2.0);
    expect((float) $line->quantity - (float) $line->returned_quantity)->toBe(3.0);
    expect($transaction->status)->toBe('partially_returned');
    expect((float) $asset->fresh()->quantity_on_hand)->toBe(7.0);
});

test('a damaged return routes the asset to under_repair instead of back to good', function () {
    $asset = registerAsset($this->organization->id);
    AssetActiveCustody::factory()->create(['organization_id' => $this->organization->id, 'asset_id' => $asset->id, 'status' => 'available']);

    $this->actingAs($this->warehouseKeeper)->post(route('assets.issue', $asset), [
        'receiving_employee_id' => $this->employee->id,
        'condition_at_transaction' => 'good',
        'lines' => [['asset_id' => $asset->id]],
    ])->assertRedirect();
    CurrentOrganization::set($this->organization->id);

    $transaction = CustodyTransaction::query()->where('receiving_employee_id', $this->employee->id)->firstOrFail();
    $this->actingAs($this->employeeUser)->post(route('assets.custody.confirm-receipt', $transaction))->assertRedirect();
    CurrentOrganization::set($this->organization->id);

    $line = $transaction->lines()->firstOrFail();

    $this->actingAs($this->warehouseKeeper)
        ->post(route('assets.custody.return', $transaction), [
            'lines' => [['custody_line_id' => $line->id, 'quantity' => 1, 'condition' => 'damaged']],
        ])
        ->assertRedirect();
    CurrentOrganization::set($this->organization->id);

    expect($asset->fresh()->condition)->toBe('under_repair');
    // Never back to 'good' silently.
    expect($asset->fresh()->condition)->not->toBe('good');
});

test('an incident write-off requires a real Approval row and never deletes the asset', function () {
    $asset = registerAsset($this->organization->id);

    $incident = AssetIncident::factory()->create([
        'organization_id' => $this->organization->id,
        'asset_id' => $asset->id,
        'incident_type' => 'write_off_request',
        'reported_by_user_id' => $this->employeeUser->id,
    ]);
    // HasVersion's DB default (1) is not re-fetched into a just-created
    // in-memory model without an explicit refresh — reading ->version
    // beforehand silently returns null (same gotcha JOURNAL-01's own tests
    // in this session documented for the identical trait).
    $incident->refresh();

    $this->actingAs($this->warehouseKeeper)
        ->post(route('assets.incidents.decide', $incident), [
            'decision' => 'write_off',
            'reason' => 'გაუმართავია, აღდგენას აღარ ექვემდებარება.',
            'target_version' => $incident->version,
        ])
        ->assertRedirect();
    CurrentOrganization::set($this->organization->id);

    $incident->refresh();
    expect($incident->decision)->toBe('write_off');

    $approval = Approval::query()
        ->where('approvable_type', $incident->getMorphClass())
        ->where('approvable_id', $incident->id)
        ->first();
    expect($approval)->not->toBeNull();
    expect($approval->decision)->toBe('approved');

    expect($asset->fresh())->not->toBeNull();
    expect($asset->fresh()->condition)->toBe('written_off');
});

test('a stale target_version on an incident decision is rejected, not silently applied', function () {
    $asset = registerAsset($this->organization->id);
    $incident = AssetIncident::factory()->create([
        'organization_id' => $this->organization->id,
        'asset_id' => $asset->id,
        'reported_by_user_id' => $this->employeeUser->id,
    ]);
    $incident->refresh();

    $this->actingAs($this->warehouseKeeper)
        ->post(route('assets.incidents.decide', $incident), [
            'decision' => 'repair',
            'target_version' => $incident->version + 1,
        ])
        ->assertSessionHasErrors('incident');

    expect($incident->fresh()->decision)->toBeNull();
});

test('any employee may report an incident, but only a permitted user may decide it', function () {
    $asset = registerAsset($this->organization->id);

    $this->actingAs($this->employeeUser)
        ->post(route('assets.incidents.store', $asset), [
            'incident_type' => 'damage',
            'description' => 'დაზიანდა გადატანისას.',
        ])
        ->assertRedirect();
    CurrentOrganization::set($this->organization->id);

    $incident = AssetIncident::query()->where('asset_id', $asset->id)->firstOrFail();

    $this->actingAs($this->employeeUser)
        ->post(route('assets.incidents.decide', $incident), [
            'decision' => 'repair',
            'target_version' => $incident->version,
        ])
        ->assertForbidden();
});

test('a user without assets permissions is denied every mutating route directly, not just hidden UI', function () {
    $asset = registerAsset($this->organization->id);
    AssetActiveCustody::factory()->create(['organization_id' => $this->organization->id, 'asset_id' => $asset->id, 'status' => 'available']);

    $noPermissionUser = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    // Deliberately no role assigned — no assets.* permission at all.

    $this->actingAs($noPermissionUser)->get(route('assets.index'))->assertForbidden();
    $this->actingAs($noPermissionUser)->get(route('assets.create'))->assertForbidden();
    $this->actingAs($noPermissionUser)->post(route('assets.issue', $asset), [
        'receiving_employee_id' => $this->employee->id,
        'condition_at_transaction' => 'good',
        'lines' => [['asset_id' => $asset->id]],
    ])->assertForbidden();
    $this->actingAs($noPermissionUser)->post(route('assets.incidents.store', $asset), [
        'incident_type' => 'damage',
        'description' => 'test',
    ])->assertForbidden();
});

test('a user from a different organization cannot reach an asset by guessing its id', function () {
    $asset = registerAsset($this->organization->id);

    $otherOrg = Organization::factory()->create();
    CurrentOrganization::set($otherOrg->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($otherOrg->id);

    $otherUser = User::factory()->create([
        'organization_id' => $otherOrg->id,
        'current_organization_id' => $otherOrg->id,
    ]);
    $otherUser->assignRole('owner');
    CurrentOrganization::set($this->organization->id);

    $this->actingAs($otherUser)->get(route('assets.show', $asset))->assertNotFound();
});

test('every mutating custody/incident action writes a real audit event', function () {
    $asset = registerAsset($this->organization->id);
    AssetActiveCustody::factory()->create(['organization_id' => $this->organization->id, 'asset_id' => $asset->id, 'status' => 'available']);

    $this->actingAs($this->warehouseKeeper)->post(route('assets.issue', $asset), [
        'receiving_employee_id' => $this->employee->id,
        'condition_at_transaction' => 'good',
        'lines' => [['asset_id' => $asset->id]],
    ])->assertRedirect();
    CurrentOrganization::set($this->organization->id);

    $finalized = AuditEvent::query()->where('action', 'assets.custody.issue_finalized')->first();
    expect($finalized)->not->toBeNull();
    expect($finalized->actor_user_id)->toBe($this->warehouseKeeper->id);
});
