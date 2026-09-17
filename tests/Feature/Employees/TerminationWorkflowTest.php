<?php

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\CustodyLine;
use App\Domain\Assets\Models\CustodyTransaction;
use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Models\Credential;
use App\Domain\Devices\Models\CredentialAssignment;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceSyncCommand;
use App\Domain\Devices\Models\Site;
use App\Domain\Employees\Actions\TerminateEmploymentAction;
use App\Domain\Employees\Models\Employee;
use App\Domain\Employees\Models\Employment;
use App\Domain\Payroll\Models\PayAdjustment;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;

pest()->group('employees');

test('termination revokes login schedules credentials and surfaces tools without charging them', function () {
    $organization = Organization::factory()->create();
    CurrentOrganization::set($organization->id);

    $actor = User::factory()->create([
        'organization_id' => $organization->id,
        'current_organization_id' => $organization->id,
    ]);
    $login = User::factory()->create([
        'organization_id' => $organization->id,
        'current_organization_id' => $organization->id,
    ]);
    $employee = Employee::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $login->id,
    ]);
    $employment = Employment::factory()->create([
        'organization_id' => $organization->id,
        'employee_id' => $employee->id,
        'status' => 'active',
    ]);

    $site = Site::factory()->create(['organization_id' => $organization->id]);
    $device = Device::factory()->create([
        'organization_id' => $organization->id,
        'site_id' => $site->id,
    ]);
    $credential = Credential::factory()->create(['organization_id' => $organization->id]);
    $assignment = CredentialAssignment::factory()->create([
        'organization_id' => $organization->id,
        'credential_id' => $credential->id,
        'employee_id' => $employee->id,
        'site_scope' => [$site->id],
        'status' => 'active',
    ]);

    $custody = CustodyTransaction::factory()->create([
        'organization_id' => $organization->id,
        'receiving_employee_id' => $employee->id,
        'issued_by_user_id' => $actor->id,
        'status' => 'issued',
    ]);
    $asset = Asset::factory()->create(['organization_id' => $organization->id]);
    CustodyLine::factory()->create([
        'organization_id' => $organization->id,
        'custody_transaction_id' => $custody->id,
        'asset_id' => $asset->id,
        'quantity' => 2,
        'returned_quantity' => 1,
    ]);

    $outcome = app(TerminateEmploymentAction::class)->execute(
        $employee,
        '2026-09-17',
        'Contract completed',
        $actor,
    );

    expect($employee->refresh()->status)->toBe('terminated')
        ->and($login->refresh()->is_active)->toBeFalse()
        ->and($employment->refresh()->status)->toBe('ended')
        ->and($employment->ended_at->toDateString())->toBe('2026-09-17')
        ->and($assignment->refresh()->status)->toBe('active')
        ->and($outcome->loginRevoked)->toBeTrue()
        ->and($outcome->deviceSyncCommandsScheduled)->toBe(1)
        ->and($outcome->unreturnedCustodyTransactions)->toHaveCount(1)
        ->and(PayAdjustment::query()->count())->toBe(0);

    $command = DeviceSyncCommand::query()->firstOrFail();
    expect($command->device_id)->toBe($device->id)
        ->and($command->command_type)->toBe('revoke_credential')
        ->and($command->status)->toBe('pending');
});
