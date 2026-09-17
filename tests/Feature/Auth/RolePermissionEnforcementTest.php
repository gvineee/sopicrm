<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Auth\Models\ProjectMembership;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\AuditLogger;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/**
 * Spec section 3: role + project membership jointly decide access, and a
 * user may hold multiple roles. Also proves the explicit carve-out
 * "სისტემურ ადმინისტრატორს ფინანსური წვდომა ავტომატურად არ მიენიჭოს"
 * (system admin does NOT automatically get financial access).
 */
function actingAsInOrganization(Organization $organization, User $user): void
{
    CurrentOrganization::set($organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);
    test()->actingAs($user);
}

beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);

    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
});

test('a project manager without project membership cannot view the project', function () {
    $user = User::factory()->create(['organization_id' => $this->organization->id, 'current_organization_id' => $this->organization->id]);
    $user->assignRole('project_manager');

    $project = Project::factory()->create(['organization_id' => $this->organization->id]);

    actingAsInOrganization($this->organization, $user);

    expect($user->can('view', $project))->toBeFalse();
});

test('a project manager who is an active member of the project can view it', function () {
    $user = User::factory()->create(['organization_id' => $this->organization->id, 'current_organization_id' => $this->organization->id]);
    $user->assignRole('project_manager');

    $project = Project::factory()->create(['organization_id' => $this->organization->id]);

    ProjectMembership::create([
        'organization_id' => $this->organization->id,
        'project_id' => $project->id,
        'user_id' => $user->id,
        'role_context' => 'manager',
    ]);

    actingAsInOrganization($this->organization, $user);

    expect($user->can('view', $project))->toBeTrue();
});

test('removing a project membership revokes access even though the role is unchanged', function () {
    $user = User::factory()->create(['organization_id' => $this->organization->id, 'current_organization_id' => $this->organization->id]);
    $user->assignRole('project_manager');

    $project = Project::factory()->create(['organization_id' => $this->organization->id]);

    $membership = ProjectMembership::create([
        'organization_id' => $this->organization->id,
        'project_id' => $project->id,
        'user_id' => $user->id,
        'role_context' => 'manager',
    ]);

    actingAsInOrganization($this->organization, $user);
    expect($user->can('view', $project))->toBeTrue();

    $membership->update(['removed_at' => now()]);

    expect($user->can('view', $project))->toBeFalse();
});

test('the owner role can view any project in its own organization without an explicit membership', function () {
    $owner = User::factory()->create(['organization_id' => $this->organization->id, 'current_organization_id' => $this->organization->id]);
    $owner->assignRole('owner');

    $project = Project::factory()->create(['organization_id' => $this->organization->id]);

    actingAsInOrganization($this->organization, $owner);

    expect($owner->can('view', $project))->toBeTrue();
});

test('a user may hold multiple roles simultaneously', function () {
    $user = User::factory()->create(['organization_id' => $this->organization->id, 'current_organization_id' => $this->organization->id]);
    $user->assignRole(['foreman', 'qa_safety']);

    expect($user->hasRole('foreman'))->toBeTrue()
        ->and($user->hasRole('qa_safety'))->toBeTrue()
        ->and($user->hasRole('owner'))->toBeFalse();
});

test('system admin does not automatically get financial access', function () {
    $admin = User::factory()->create(['organization_id' => $this->organization->id, 'current_organization_id' => $this->organization->id]);
    $admin->assignRole('system_admin');

    actingAsInOrganization($this->organization, $admin);

    expect(Gate::forUser($admin)->allows('access-financial-data'))->toBeFalse();
});

test('the finance role has the permission but is still denied financial access without confirmed MFA', function () {
    $finance = User::factory()->create(['organization_id' => $this->organization->id, 'current_organization_id' => $this->organization->id]);
    $finance->assignRole('finance');

    actingAsInOrganization($this->organization, $finance);

    expect($finance->can('finance.access'))->toBeTrue()
        ->and(Gate::forUser($finance)->allows('access-financial-data'))->toBeFalse();
});

test('the finance role gets financial access once two-factor authentication is confirmed', function () {
    $finance = User::factory()->withTwoFactor()->create(['organization_id' => $this->organization->id, 'current_organization_id' => $this->organization->id]);
    $finance->assignRole('finance');

    actingAsInOrganization($this->organization, $finance);

    expect(Gate::forUser($finance)->allows('access-financial-data'))->toBeTrue();
});

test('owner has financial access via an explicit grant plus confirmed MFA, not a role-name bypass', function () {
    $owner = User::factory()->withTwoFactor()->create(['organization_id' => $this->organization->id, 'current_organization_id' => $this->organization->id]);
    $owner->assignRole('owner');

    actingAsInOrganization($this->organization, $owner);

    expect(Gate::forUser($owner)->allows('access-financial-data'))->toBeTrue();
});

test('audit events can never be updated or deleted by an ordinary admin, even one with view/export permission', function () {
    $owner = User::factory()->create(['organization_id' => $this->organization->id, 'current_organization_id' => $this->organization->id]);
    $owner->assignRole('owner');

    $auditEvent = app(AuditLogger::class)->log(
        action: 'test.action',
        target: $owner,
    );

    actingAsInOrganization($this->organization, $owner);

    expect($owner->can('update', $auditEvent))->toBeFalse()
        ->and($owner->can('delete', $auditEvent))->toBeFalse()
        ->and($owner->can('view', $auditEvent))->toBeTrue();
});
