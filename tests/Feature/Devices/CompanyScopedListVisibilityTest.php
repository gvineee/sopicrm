<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Companies\Models\Company;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\Site;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

pest()->group('tenant', 'devices');

/**
 * Audit A14 asks for the one test that was missing: „აუცილებელია სხვადასხვა
 * კომპანიის ანგარიშებით ხილვადობის ტესტი" — visibility checked with accounts
 * belonging to different companies.
 *
 * The existing company-scope suite only ever asked the Policy
 * (`$user->can('view', $record)`). That is not the same question as "what
 * does this person actually see on screen": a list endpoint builds its own
 * query and is not obliged to consult the Policy for each row. These tests
 * ask through HTTP, one company-scoped account at a time, because that is
 * where a disclosure would actually happen.
 *
 * The rule under test (App\Domain\Devices\Support\CompanyScope): a record
 * with no company is shared and stays visible to anyone the organization
 * already let see it; a record assigned to a company is visible only within
 * that company, except to `owner`/`system_admin`, who are org-wide by design.
 */
beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);

    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->companyA = Company::factory()->create(['organization_id' => $this->organization->id, 'name' => 'ალფა მშენებლობა']);
    $this->companyB = Company::factory()->create(['organization_id' => $this->organization->id, 'name' => 'ბეტა მშენებლობა']);

    // HR is genuinely company-scoped and genuinely holds
    // `employees.employees.view`, so the roster leak below was reachable in
    // production exactly as written.
    $this->userA = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'current_company_id' => $this->companyA->id,
    ]);
    $this->userA->assignRole('hr');

    // Sites and Devices are a different case, and worth being precise about:
    // today only `owner`/`system_admin` hold `devices.view`, and CompanyScope
    // treats both as org-wide, so no company-scoped account can open those
    // lists at all — there is no live disclosure there. That safety rests
    // entirely on one seeder's grants, though, so this account is given the
    // permission directly to pin down what the QUERY guarantees if
    // `devices.view` is ever granted to a company-scoped role.
    $this->deviceViewerA = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'current_company_id' => $this->companyA->id,
    ]);
    $this->deviceViewerA->givePermissionTo('devices.view');

    $this->owner = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'current_company_id' => $this->companyA->id,
    ]);
    $this->owner->assignRole('owner');

    $this->siteA = Site::factory()->create([
        'organization_id' => $this->organization->id,
        'company_id' => $this->companyA->id,
        'name' => 'ალფას ობიექტი',
    ]);
    $this->siteB = Site::factory()->create([
        'organization_id' => $this->organization->id,
        'company_id' => $this->companyB->id,
        'name' => 'ბეტას ობიექტი',
    ]);
    $this->sharedSite = Site::factory()->create([
        'organization_id' => $this->organization->id,
        'company_id' => null,
        'name' => 'საერთო ობიექტი',
    ]);
});

test('the sites list shows another company\'s site to nobody but an org-wide role', function () {
    $response = $this->actingAs($this->deviceViewerA)->get(route('sites.index'))->assertOk();

    $names = collect($response->viewData('page')['props']['sites'])->pluck('name')->all();

    expect($names)->toContain($this->siteA->name)
        // A shared, unassigned site stays visible — company scoping only ever
        // tightens what an assignment covers, it never hides data that was
        // organization-wide before anyone was assigned.
        ->and($names)->toContain($this->sharedSite->name)
        ->and($names)->not->toContain($this->siteB->name);

    CurrentOrganization::set($this->organization->id);

    // The owner is org-wide by design and must still see everything, or this
    // would be a regression dressed up as a fix.
    $ownerResponse = $this->actingAs($this->owner)->get(route('sites.index'))->assertOk();
    $ownerNames = collect($ownerResponse->viewData('page')['props']['sites'])->pluck('name')->all();

    expect($ownerNames)->toContain($this->siteA->name)
        ->and($ownerNames)->toContain($this->siteB->name)
        ->and($ownerNames)->toContain($this->sharedSite->name);
});

test('the devices list follows the company of the site each device sits on', function () {
    $deviceA = Device::factory()->create([
        'organization_id' => $this->organization->id,
        'site_id' => $this->siteA->id,
    ]);
    $deviceB = Device::factory()->create([
        'organization_id' => $this->organization->id,
        'site_id' => $this->siteB->id,
    ]);
    $sharedDevice = Device::factory()->create([
        'organization_id' => $this->organization->id,
        'site_id' => $this->sharedSite->id,
    ]);

    $response = $this->actingAs($this->deviceViewerA)->get(route('devices.index'))->assertOk();
    $ids = collect($response->viewData('page')['props']['devices']['data'])->pluck('id')->all();

    expect($ids)->toContain($deviceA->id)
        ->and($ids)->toContain($sharedDevice->id)
        ->and($ids)->not->toContain($deviceB->id);

    CurrentOrganization::set($this->organization->id);

    $ownerResponse = $this->actingAs($this->owner)->get(route('devices.index'))->assertOk();
    $ownerIds = collect($ownerResponse->viewData('page')['props']['devices']['data'])->pluck('id')->all();

    expect($ownerIds)->toContain($deviceB->id);
});

test('the employees list does not show another company\'s roster', function () {
    $employeeA = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'company_id' => $this->companyA->id,
    ]);
    $employeeB = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'company_id' => $this->companyB->id,
    ]);
    $unmapped = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'company_id' => null,
    ]);

    $response = $this->actingAs($this->userA)->get(route('employees.index'))->assertOk();
    $ids = collect($response->viewData('page')['props']['employees']['data'])->pluck('id')->all();

    expect($ids)->toContain($employeeA->id)
        ->and($ids)->toContain($unmapped->id)
        ->and($ids)->not->toContain($employeeB->id);

    CurrentOrganization::set($this->organization->id);

    $ownerResponse = $this->actingAs($this->owner)->get(route('employees.index'))->assertOk();
    $ownerIds = collect($ownerResponse->viewData('page')['props']['employees']['data'])->pluck('id')->all();

    expect($ownerIds)->toContain($employeeB->id);
});

test('a list and the detail page it links to agree about what is visible', function () {
    // The disclosure that matters is the mismatch: a row a person can read in
    // a list but is forbidden to open tells them the record exists, who it
    // belongs to and roughly what it is, while the Policy reports that access
    // was denied.
    $this->actingAs($this->deviceViewerA)->get(route('sites.index'))->assertOk();

    CurrentOrganization::set($this->organization->id);

    expect($this->deviceViewerA->can('view', $this->siteB))->toBeFalse()
        ->and($this->deviceViewerA->can('view', $this->siteA))->toBeTrue()
        ->and($this->deviceViewerA->can('view', $this->sharedSite))->toBeTrue();
});
