<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
});

test('any authenticated user can open their own profile page, even without an employee record', function () {
    $user = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);

    $this->actingAs($user)
        ->get(route('me.profile'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Me/Profile')->where('employee', null));
});

test('a user linked to an employee record sees their own real profile data', function () {
    $user = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $employee = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $user->id,
        'first_name' => 'Nino',
        'last_name' => 'Beridze',
    ]);

    $this->actingAs($user)
        ->get(route('me.profile'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Me/Profile')
            ->where('employee.id', $employee->id)
            ->where('employee.full_name', 'Nino Beridze')
            ->where('recentAccessEvents', []));
});
