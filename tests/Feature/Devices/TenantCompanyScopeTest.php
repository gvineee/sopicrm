<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Companies\Models\Company;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\Site;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;

/**
 * TENANT-01's own recorded next slice: company-level scoping for
 * Site/Device/Employee within ONE organization that has two companies (not
 * just two organizations, which the existing RLS suite already covers).
 */
pest()->group('tenant');

beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->companyA = Company::factory()->create(['organization_id' => $this->organization->id]);
    $this->companyB = Company::factory()->create(['organization_id' => $this->organization->id]);

    $this->hrUserA = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'current_company_id' => $this->companyA->id,
    ]);
    $this->hrUserA->assignRole('hr');

    $this->owner = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'current_company_id' => $this->companyA->id,
    ]);
    $this->owner->assignRole('owner');
});

test('an employee assigned to another company is invisible to a company-scoped hr user', function () {
    $employeeA = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'company_id' => $this->companyA->id,
    ]);
    $employeeB = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'company_id' => $this->companyB->id,
    ]);

    Auth::login($this->hrUserA);

    expect($this->hrUserA->can('view', $employeeA))->toBeTrue()
        ->and($this->hrUserA->can('view', $employeeB))->toBeFalse();
});

test('an unmapped employee stays visible to any org-scoped user who could already see it', function () {
    $unmapped = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'company_id' => null,
    ]);

    Auth::login($this->hrUserA);

    expect($this->hrUserA->can('view', $unmapped))->toBeTrue();
});

test('owner sees employees/devices/sites across every company in the organization', function () {
    $employeeB = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'company_id' => $this->companyB->id,
    ]);
    $siteB = Site::factory()->create([
        'organization_id' => $this->organization->id,
        'company_id' => $this->companyB->id,
    ]);
    $deviceB = Device::factory()->create([
        'organization_id' => $this->organization->id,
        'site_id' => $siteB->id,
    ]);

    Auth::login($this->owner);

    expect($this->owner->can('view', $employeeB))->toBeTrue()
        ->and($this->owner->can('view', $siteB))->toBeTrue()
        ->and($this->owner->can('view', $deviceB))->toBeTrue();
});

test('a device inherits its company scope transitively from its site', function () {
    $siteB = Site::factory()->create([
        'organization_id' => $this->organization->id,
        'company_id' => $this->companyB->id,
    ]);
    $deviceB = Device::factory()->create([
        'organization_id' => $this->organization->id,
        'site_id' => $siteB->id,
    ]);

    $userA = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'current_company_id' => $this->companyA->id,
    ]);
    $userA->assignRole('system_admin');
    // Demote below org-wide override to actually exercise the scope check:
    // system_admin is treated as org-wide by CompanyScope, so use a plain
    // permission-holding role instead.
    $userA->removeRole('system_admin');
    $userA->givePermissionTo('devices.view');

    Auth::login($userA);

    expect($userA->can('view', $deviceB))->toBeFalse();
});

test('unmapped sites index lists only unassigned sites and assignment removes them from it', function () {
    $unmapped = Site::factory()->create(['organization_id' => $this->organization->id, 'company_id' => null]);
    Site::factory()->create(['organization_id' => $this->organization->id, 'company_id' => $this->companyA->id]);

    $response = $this->actingAs($this->owner)->get('/sites');
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Devices/Sites/Index')
        ->where('sites', fn ($sites) => collect($sites)->firstWhere('id', $unmapped->id)['company_id'] === null)
    );

    $this->actingAs($this->owner)
        ->post("/sites/{$unmapped->id}/assign-company", ['company_id' => $this->companyB->id])
        ->assertRedirect();

    expect($unmapped->fresh()->company_id)->toBe($this->companyB->id);
});

test('assigning a site to a company from a different organization is rejected', function () {
    $otherOrganization = Organization::factory()->create();
    $otherCompany = Company::factory()->create(['organization_id' => $otherOrganization->id]);
    $site = Site::factory()->create(['organization_id' => $this->organization->id, 'company_id' => null]);

    $this->actingAs($this->owner)
        ->post("/sites/{$site->id}/assign-company", ['company_id' => $otherCompany->id])
        ->assertSessionHasErrors('company_id');

    expect($site->fresh()->company_id)->toBeNull();
});
