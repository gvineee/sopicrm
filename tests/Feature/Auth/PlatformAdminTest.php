<?php

use App\Domain\Auth\Actions\GrantPlatformAdminAction;
use App\Domain\Auth\Actions\RevokePlatformAdminAction;
use App\Domain\Auth\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

pest()->group('auth');

/**
 * ADMIN-01 (docs/claude-platform-completion-2026-09-21.md, audit finding A1):
 * regression coverage for replacing the `admin@protect.ge` email-string
 * `Gate::before` bypass with the durable `users.is_platform_admin` grant.
 */
beforeEach(function () {
    $this->organization = Organization::factory()->create();
});

test('the bootstrap admin@protect.ge account is granted platform admin by the migration backfill', function () {
    $bootstrap = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'email' => 'admin@protect.ge',
    ]);

    // The migration's backfill only runs once, at migration time, against
    // whatever row already had this email then — simulate it directly here
    // since Pest's migrations already ran before this row existed.
    DB::table('users')->where('email', 'admin@protect.ge')->update(['is_platform_admin' => true]);

    expect($bootstrap->fresh()->is_platform_admin)->toBeTrue();
});

test('a platform admin passes an arbitrary ability check via Gate::before, an ordinary user does not', function () {
    Gate::define('platform-admin-test-ability', fn () => false);

    $admin = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'is_platform_admin' => true,
    ]);
    $ordinary = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'is_platform_admin' => false,
    ]);

    expect($admin->can('platform-admin-test-ability'))->toBeTrue()
        ->and($ordinary->can('platform-admin-test-ability'))->toBeFalse();
});

test('platform admin does not bypass the MFA-gated access-financial-data ability', function () {
    $admin = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'is_platform_admin' => true,
        'two_factor_confirmed_at' => null,
    ]);

    expect(Gate::forUser($admin)->allows('access-financial-data'))->toBeFalse();
});

test('changing the platform admin\'s email neither drops their access nor transfers it to a new account with the old address', function () {
    $admin = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'is_platform_admin' => true,
        'email' => 'admin@protect.ge',
    ]);

    $admin->forceFill(['email' => 'renamed@protect.ge'])->save();
    expect($admin->fresh()->is_platform_admin)->toBeTrue();

    $newAccountSameOldEmail = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'email' => 'admin@protect.ge',
    ]);
    // The freshly-inserted row never had is_platform_admin explicitly set,
    // so it must be re-read from the DB to see the column's real default
    // rather than the in-memory model's unset attribute.
    expect($newAccountSameOldEmail->fresh()->is_platform_admin)->toBeFalse();
});

test('is_platform_admin cannot be set through ordinary mass assignment', function () {
    $user = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'is_platform_admin' => false,
    ]);

    $user->update(['name' => 'Still Not Admin', 'is_platform_admin' => true]);

    expect($user->fresh()->is_platform_admin)->toBeFalse();
});

test('GrantPlatformAdminAction refuses a non-admin actor and RevokePlatformAdminAction refuses to remove the last admin', function () {
    $admin = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'is_platform_admin' => true,
    ]);
    $ordinary = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'is_platform_admin' => false,
    ]);

    expect(fn () => app(GrantPlatformAdminAction::class)->execute($ordinary, $ordinary, 'self-grant attempt'))
        ->toThrow(AuthorizationException::class);

    expect($ordinary->fresh()->is_platform_admin)->toBeFalse();

    expect(fn () => app(RevokePlatformAdminAction::class)->execute($admin, $admin, 'removing the only admin'))
        ->toThrow(RuntimeException::class);

    expect($admin->fresh()->is_platform_admin)->toBeTrue();

    // With a second admin present, revoking the first now succeeds.
    app(GrantPlatformAdminAction::class)->execute($ordinary, $admin, 'promote a second admin');
    app(RevokePlatformAdminAction::class)->execute($admin, $ordinary, 'first admin stepping down');

    expect($admin->fresh()->is_platform_admin)->toBeFalse()
        ->and($ordinary->fresh()->is_platform_admin)->toBeTrue();
});
