<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Shared\Services\NavigationService;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Illuminate\Support\Facades\File;
use Spatie\Permission\PermissionRegistrar;

/**
 * docs/architecture.md §3.2/§7: NavigationService is the actual server-side
 * enforcement point for menu visibility (permission-filtered at render
 * time), and it must glob every config/modules/<module>-nav.php file
 * without requiring the aggregator itself to be edited per module.
 */
beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);

    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
});

test('an authenticated user sees the shared dashboard entry under its Georgian group', function () {
    $user = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);

    $groups = app(NavigationService::class)->groupsForUser($user);

    $overviewGroup = collect($groups)->firstWhere('group', 'მიმოხილვა');

    expect($overviewGroup)->not->toBeNull()
        ->and($overviewGroup['items'])->toContain(
            ['label' => 'დეშბორდი', 'icon' => 'layout-grid', 'href' => route('dashboard')],
        );
});

test('a guest (no authenticated user) sees no nav entries', function () {
    $groups = app(NavigationService::class)->groupsForUser(null);

    expect($groups)->toBe([]);
});

describe('a real second module nav file dropped into config/modules', function () {
    beforeEach(function () {
        // Exercises the real glob-and-require mechanism end to end (not a
        // mock) — a fixture module contributes an ungated item plus one
        // gated behind a permission this test's user is never granted.
        File::put(config_path('modules/zzz-fixture-nav.php'), <<<'PHP'
            <?php

            return [
                [
                    'group' => 'ანგარიშები',
                    'items' => [
                        [
                            'label' => 'ყველასთვის ხილული',
                            'icon' => 'layout-grid',
                            'route' => 'dashboard',
                            'permission' => null,
                        ],
                        [
                            'label' => 'მხოლოდ ფინანსისტისთვის',
                            'icon' => 'layout-grid',
                            'route' => 'dashboard',
                            'permission' => 'finance.access',
                        ],
                        [
                            'label' => 'არარსებული route',
                            'icon' => 'layout-grid',
                            'route' => 'this-route-does-not-exist',
                            'permission' => null,
                        ],
                    ],
                ],
            ];
            PHP);
    });

    afterEach(function () {
        File::delete(config_path('modules/zzz-fixture-nav.php'));
    });

    test('an item gated behind a permission the user lacks is excluded, an ungated item is kept, and a nonexistent route is skipped without throwing', function () {
        $user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'current_organization_id' => $this->organization->id,
        ]);

        $groups = app(NavigationService::class)->groupsForUser($user);

        $reportsGroup = collect($groups)->firstWhere('group', 'ანგარიშები');

        expect($reportsGroup)->not->toBeNull()
            ->and(collect($reportsGroup['items'])->pluck('label')->all())
            ->toBe(['ყველასთვის ხილული']);
    });

    test('a user who does hold the gating permission sees the gated item too', function () {
        $user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'current_organization_id' => $this->organization->id,
        ]);
        $user->givePermissionTo('finance.access');

        $groups = app(NavigationService::class)->groupsForUser($user);

        $reportsGroup = collect($groups)->firstWhere('group', 'ანგარიშები');

        expect(collect($reportsGroup['items'])->pluck('label')->all())
            ->toBe(['ყველასთვის ხილული', 'მხოლოდ ფინანსისტისთვის']);
    });
});
