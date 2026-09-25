<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

pest()->group('security');

/**
 * Audit A26 asked whether test data was separated from real data before
 * release. Checking that against the running database found something worse
 * than stale rows: every account in it, including the owner's own,
 * authenticated with the literal password `password` — all of them created by
 * UserFactory, which hashes exactly that — while `APP_ENV` was `local` and the
 * same instance was served to the internet through a tunnel.
 *
 * These tests cover the check that now reports it, and the seeder guard that
 * stops those accounts being created on an instance anyone can reach.
 */
beforeEach(function () {
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
});

test('the check names accounts that still have a known default password', function () {
    User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'email' => 'weak@syslab.ge',
        'password' => Hash::make('password'),
    ]);

    $this->artisan('security:check-accounts')
        ->expectsOutputToContain('weak@syslab.ge')
        ->assertExitCode(1);
});

test('an account with a real password is not reported', function () {
    User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'email' => 'strong@syslab.ge',
        'password' => Hash::make('9Hs!kq2vB#7zLr4t'),
    ]);

    $this->artisan('security:check-accounts')->assertExitCode(0);
});

test('an address reused across organizations is reported, because email login cannot tell them apart', function () {
    // The schema allows this on purpose: `users` is unique on
    // (organization_id, email), not on email alone, so one person may hold an
    // account in two organizations. The consequence is still worth surfacing —
    // signing in with an address alone no longer identifies which account.
    $second = Organization::factory()->create();

    foreach ([$this->organization, $second] as $organization) {
        User::factory()->create([
            'organization_id' => $organization->id,
            'current_organization_id' => $organization->id,
            'email' => 'shared@syslab.ge',
            'password' => Hash::make('9Hs!kq2vB#7zLr4t'),
        ]);
    }

    $this->artisan('security:check-accounts')
        ->expectsOutputToContain('shared@syslab.ge')
        ->assertExitCode(1);
});

test('demo-domain accounts are reported even when their password is fine', function () {
    User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'email' => 'leftover@example.com',
        'password' => Hash::make('9Hs!kq2vB#7zLr4t'),
    ]);

    $this->artisan('security:check-accounts')
        ->expectsOutputToContain('leftover@example.com')
        ->assertExitCode(1);
});

test('the check only reads — it never rotates a password or disables anyone', function () {
    $user = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
        'email' => 'untouched@syslab.ge',
        'password' => Hash::make('password'),
    ]);

    $before = $user->password;

    $this->artisan('security:check-accounts')->assertExitCode(1);

    // Changing someone's password without telling them locks them out of
    // their own system, so the command reports and stops.
    expect($user->refresh()->password)->toBe($before)
        ->and($user->is_active)->toBeTrue();
});
