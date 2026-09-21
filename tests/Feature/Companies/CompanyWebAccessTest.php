<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Companies\Models\Company;
use App\Domain\Companies\Models\CompanyMembership;
use App\Domain\Shared\Models\AuditEvent;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

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
});

test('authorized owner can create list and update a company with audit history', function () {
    $this->actingAs($this->owner)
        ->get(route('companies.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Companies/Index')->where('canManage', true));

    $this->actingAs($this->owner)->post(route('companies.store'), [
        'name' => 'ODA Construction',
        'legal_name' => 'ODA Construction LLC',
        'code' => 'ODA-GE',
        'default_currency' => 'gel',
        'default_timezone' => 'Asia/Tbilisi',
        'is_active' => true,
    ])->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $company = Company::query()->where('code', 'ODA-GE')->sole();

    expect($company->default_currency)->toBe('GEL')
        ->and(CompanyMembership::query()->where('company_id', $company->id)->where('user_id', $this->owner->id)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'companies.company.created')->exists())->toBeTrue();

    $this->actingAs($this->owner)->patch(route('companies.update', $company), [
        'name' => 'ODA Group',
        'legal_name' => 'ODA Construction LLC',
        'code' => 'ODA-GE',
        'default_currency' => 'GEL',
        'default_timezone' => 'Asia/Tbilisi',
        'is_active' => false,
    ])->assertRedirect(route('companies.index'));

    CurrentOrganization::set($this->organization->id);
    expect($company->refresh()->name)->toBe('ODA Group')
        ->and($company->is_active)->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'companies.company.updated')->exists())->toBeTrue();
});

test('owner can create a second company in the organization when both leave code blank', function () {
    $this->actingAs($this->owner)->post(route('companies.store'), [
        'name' => 'ODA Construction',
        'legal_name' => null,
        'code' => '',
        'default_currency' => 'GEL',
        'default_timezone' => 'Asia/Tbilisi',
        'is_active' => true,
    ])->assertRedirect(route('companies.index'));

    $this->actingAs($this->owner)->post(route('companies.store'), [
        'name' => 'ODA Interiors',
        'legal_name' => null,
        'code' => '',
        'default_currency' => 'GEL',
        'default_timezone' => 'Asia/Tbilisi',
        'is_active' => true,
    ])->assertRedirect(route('companies.index'))
        ->assertSessionDoesntHaveErrors('code');

    CurrentOrganization::set($this->organization->id);
    expect(Company::query()->whereNull('code')->count())->toBe(2);
});

test('newly created company appears in the index page immediately', function () {
    $this->actingAs($this->owner)->post(route('companies.store'), [
        'name' => 'ODA Fresh Company',
        'legal_name' => null,
        'code' => '',
        'default_currency' => 'GEL',
        'default_timezone' => 'Asia/Tbilisi',
        'is_active' => true,
    ])->assertRedirect(route('companies.index'));

    $this->actingAs($this->owner)
        ->get(route('companies.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Companies/Index')
            ->where('companies', fn ($companies) => collect($companies)->pluck('name')->contains('ODA Fresh Company')));
});

test('user without company permissions is forbidden', function () {
    $user = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);

    $this->actingAs($user)->get(route('companies.index'))->assertForbidden();
    $this->actingAs($user)->get(route('companies.create'))->assertForbidden();
});

test('company route binding cannot cross organization boundaries', function () {
    $otherOrganization = Organization::factory()->create();
    CurrentOrganization::set($otherOrganization->id);
    $foreignCompany = Company::factory()->create(['organization_id' => $otherOrganization->id]);

    CurrentOrganization::set($this->organization->id);
    $this->actingAs($this->owner)->get(route('companies.edit', $foreignCompany))->assertNotFound();
});
