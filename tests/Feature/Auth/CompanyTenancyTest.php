<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Companies\Models\Company;
use App\Domain\Companies\Models\CompanyMembership;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Illuminate\Database\QueryException;

test('companies remain isolated by the organization tenant scope', function () {
    $firstOrganization = Organization::factory()->create();
    $secondOrganization = Organization::factory()->create();

    CurrentOrganization::set($firstOrganization->id);
    $visible = Company::factory()->create(['organization_id' => $firstOrganization->id]);

    CurrentOrganization::set($secondOrganization->id);
    Company::factory()->create(['organization_id' => $secondOrganization->id]);

    CurrentOrganization::set($firstOrganization->id);

    expect(Company::query()->pluck('id')->all())->toBe([$visible->id]);
});

test('database constraints reject cross-organization company membership', function () {
    $firstOrganization = Organization::factory()->create();
    $secondOrganization = Organization::factory()->create();

    CurrentOrganization::set($firstOrganization->id);
    $company = Company::factory()->create(['organization_id' => $firstOrganization->id]);
    $foreignUser = User::factory()->create([
        'organization_id' => $secondOrganization->id,
        'current_organization_id' => $secondOrganization->id,
    ]);

    expect(fn () => CompanyMembership::query()->create([
        'organization_id' => $firstOrganization->id,
        'company_id' => $company->id,
        'user_id' => $foreignUser->id,
        'is_primary' => true,
    ]))->toThrow(QueryException::class);
});

test('a user can have only one primary company per organization', function () {
    $organization = Organization::factory()->create();
    CurrentOrganization::set($organization->id);

    $firstCompany = Company::factory()->create(['organization_id' => $organization->id]);
    $secondCompany = Company::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'current_organization_id' => $organization->id,
        'current_company_id' => $firstCompany->id,
    ]);

    CompanyMembership::factory()->create([
        'organization_id' => $organization->id,
        'company_id' => $firstCompany->id,
        'user_id' => $user->id,
        'is_primary' => true,
    ]);

    expect(fn () => CompanyMembership::factory()->create([
        'organization_id' => $organization->id,
        'company_id' => $secondCompany->id,
        'user_id' => $user->id,
        'is_primary' => true,
    ]))->toThrow(QueryException::class);
});
