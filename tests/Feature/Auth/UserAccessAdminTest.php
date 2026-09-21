<?php

use App\Domain\Auth\Actions\DenyUserPermissionAction;
use App\Domain\Auth\Actions\RemoveUserRoleAction;
use App\Domain\Auth\Models\Organization;
use App\Domain\Auth\Models\UserPermissionDenial;
use App\Domain\Auth\Support\PermissionDenialCache;
use App\Domain\Shared\Models\AuditEvent;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Shared\Services\NavigationService;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

pest()->group('auth');

/**
 * ADMIN-02: user/role/permission-override administration
 * (App\Http\Controllers\Admin\{RoleController,UserAccessController}) and
 * the deny-override precedence enforced in
 * App\Providers\AppServiceProvider::boot()'s Gate::before.
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

    $this->target = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
});

test('an owner can view the roles page and a user\'s effective permissions', function () {
    $this->actingAs($this->owner)
        ->get(route('admin.roles.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Roles/Index')
            ->where('roles', fn ($roles) => collect($roles)->pluck('name')->contains('owner')));

    $this->target->assignRole('employee');

    $this->actingAs($this->owner)
        ->get(route('admin.users.show', $this->target))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Users/Show')
            ->where('assignedRoles', ['employee'])
            ->where('canManageRoles', true)
            ->where('canManageOverrides', true));
});

test('an owner can assign and remove a role, both audited', function () {
    $this->actingAs($this->owner)
        ->post(route('admin.users.roles.assign', $this->target), ['role' => 'employee'])
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    expect($this->target->fresh()->hasRole('employee'))->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'auth.user_role.assigned')->where('actor_user_id', $this->owner->id)->exists())->toBeTrue();

    $this->actingAs($this->owner)
        ->delete(route('admin.users.roles.remove', $this->target), ['role' => 'employee', 'reason' => 'role reassignment'])
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    expect($this->target->fresh()->hasRole('employee'))->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'auth.user_role.removed')->where('reason', 'role reassignment')->exists())->toBeTrue();
});

test('removing a role without a reason is rejected by validation', function () {
    $this->target->assignRole('employee');

    $this->actingAs($this->owner)
        ->delete(route('admin.users.roles.remove', $this->target), ['role' => 'employee'])
        ->assertSessionHasErrors('reason');

    CurrentOrganization::set($this->organization->id);
    expect($this->target->fresh()->hasRole('employee'))->toBeTrue();
});

test('a user may not remove their own role', function () {
    $this->owner->assignRole('employee');

    expect(fn () => app(RemoveUserRoleAction::class)->execute($this->owner, 'employee', $this->owner, 'self removal attempt'))
        ->toThrow(AuthorizationException::class);

    CurrentOrganization::set($this->organization->id);
    expect($this->owner->fresh()->hasRole('employee'))->toBeTrue();
});

test('removing the owner role from the last owner/system_admin user in the organization is refused', function () {
    expect(fn () => app(RemoveUserRoleAction::class)->execute($this->owner, 'owner', $this->target, 'testing last-admin guard'))
        ->toThrow(RuntimeException::class);

    CurrentOrganization::set($this->organization->id);
    expect($this->owner->fresh()->hasRole('owner'))->toBeTrue();
});

test('a grant-override adds a permission the role does not provide, and can be revoked back to baseline', function () {
    $this->target->assignRole('employee');

    expect($this->target->fresh()->can('finance.access'))->toBeFalse();

    $this->actingAs($this->owner)
        ->post(route('admin.users.overrides.grant', $this->target), ['permission' => 'finance.access'])
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
    expect($this->target->fresh()->can('finance.access'))->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'auth.user_permission_override.granted')->exists())->toBeTrue();

    $this->actingAs($this->owner)
        ->post(route('admin.users.overrides.revoke', $this->target), ['permission' => 'finance.access'])
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
    expect($this->target->fresh()->can('finance.access'))->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'auth.user_permission_override.revoked')->exists())->toBeTrue();
});

test('a deny-override removes access even for a permission the role would otherwise grant, and can be removed to restore it', function () {
    $this->target->assignRole('finance');

    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
    expect($this->target->fresh()->can('finance.access'))->toBeTrue();

    $this->actingAs($this->owner)
        ->post(route('admin.users.denials.store', $this->target), ['permission' => 'finance.access', 'reason' => 'temporary restriction'])
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
    expect($this->target->fresh()->can('finance.access'))->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'auth.user_permission.denied')->where('reason', 'temporary restriction')->exists())->toBeTrue();

    $denial = UserPermissionDenial::query()->where('user_id', $this->target->id)->where('permission_name', 'finance.access')->sole();

    $this->actingAs($this->owner)
        ->delete(route('admin.users.denials.remove', [$this->target, $denial]))
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
    expect($this->target->fresh()->can('finance.access'))->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'auth.user_permission_denial.removed')->exists())->toBeTrue();
});

test('a deny-override wins even over the platform-admin Gate::before bypass', function () {
    $this->target->is_platform_admin = true;
    $this->target->save();

    expect($this->target->fresh()->can('some-arbitrary-ability-nobody-defines'))->toBeTrue();

    UserPermissionDenial::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->target->id,
        'permission_name' => 'finance.access',
        'created_by_user_id' => $this->owner->id,
    ]);
    // Mirrors what DenyUserPermissionAction does after a real write — this
    // test creates the row directly via factory rather than through the
    // Action, so it must bust the cache itself the same way.
    PermissionDenialCache::forget($this->organization->id, $this->target->id);

    expect($this->target->fresh()->can('finance.access'))->toBeFalse()
        ->and($this->target->fresh()->can('some-arbitrary-ability-nobody-defines'))->toBeTrue();
});

test('a user may not deny their own permission', function () {
    expect(fn () => app(DenyUserPermissionAction::class)->execute($this->owner, 'finance.access', $this->owner, 'self deny attempt'))
        ->toThrow(AuthorizationException::class);

    CurrentOrganization::set($this->organization->id);
    expect(UserPermissionDenial::query()->where('user_id', $this->owner->id)->exists())->toBeFalse();
});

test('a plain employee is forbidden from every admin route, hit directly', function () {
    $employee = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $employee->assignRole('employee');

    $this->actingAs($employee)->get(route('admin.roles.index'))->assertForbidden();
    $this->actingAs($employee)->get(route('admin.users.index'))->assertForbidden();
    $this->actingAs($employee)->get(route('admin.users.show', $this->target))->assertForbidden();
    $this->actingAs($employee)->post(route('admin.users.roles.assign', $this->target), ['role' => 'hr'])->assertForbidden();
    $this->actingAs($employee)->delete(route('admin.users.roles.remove', $this->target), ['role' => 'hr', 'reason' => 'test reason'])->assertForbidden();
    $this->actingAs($employee)->post(route('admin.users.overrides.grant', $this->target), ['permission' => 'finance.access'])->assertForbidden();
    $this->actingAs($employee)->post(route('admin.users.overrides.revoke', $this->target), ['permission' => 'finance.access'])->assertForbidden();
    $this->actingAs($employee)->post(route('admin.users.denials.store', $this->target), ['permission' => 'finance.access', 'reason' => 'test reason'])->assertForbidden();

    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
    expect($this->target->fresh()->hasRole('hr'))->toBeFalse();
});

test('the admin nav entries are hidden without auth.users.view and shown with it', function () {
    $employee = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);

    $groups = app(NavigationService::class)->groupsForUser($employee);
    expect(collect($groups)->firstWhere('group', 'ადმინისტრირება'))->toBeNull();

    $employee->givePermissionTo('auth.users.view');
    $groups = app(NavigationService::class)->groupsForUser($employee->fresh());
    $adminGroup = collect($groups)->firstWhere('group', 'ადმინისტრირება');

    expect($adminGroup)->not->toBeNull()
        ->and(collect($adminGroup['items'])->pluck('label')->all())->toBe(['მომხმარებლები და წვდომები', 'როლები']);
});
