<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Notifications\Adapters\FakeTelegramTransport;
use App\Domain\Notifications\Models\TelegramLink;
use App\Domain\Notifications\Models\TelegramReportDelivery;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

pest()->group('notifications', 'telegram');

beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);
});

function makeLinkedUser(Organization $organization, string $role = 'employee'): array
{
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'current_organization_id' => $organization->id,
    ]);
    $user->assignRole($role);

    $link = TelegramLink::factory()->linked()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
    ]);

    return [$user, $link];
}

test('a linked, permitted user gets exactly one delivery per report type per day, and a second run the same day does not double-send', function () {
    $organization = Organization::factory()->create();
    CurrentOrganization::set($organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);

    [$user, $link] = makeLinkedUser($organization, 'employee');
    $user->givePermissionTo('tasks.tasks.view');

    $this->artisan('telegram:send-digest')->assertSuccessful();

    $overdueDeliveries = TelegramReportDelivery::query()
        ->where('telegram_link_id', $link->id)
        ->where('report_type', 'overdue_work')
        ->get();
    expect($overdueDeliveries)->toHaveCount(1);
    expect($overdueDeliveries->first()->status)->toBe('sent');

    $this->artisan('telegram:send-digest')->assertSuccessful();

    expect(TelegramReportDelivery::query()
        ->where('telegram_link_id', $link->id)
        ->where('report_type', 'overdue_work')
        ->count())->toBe(1);
});

test('a user without access-financial-data never receives the financial summary, and no delivery row is created for it', function () {
    $organization = Organization::factory()->create();
    CurrentOrganization::set($organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);

    [, $link] = makeLinkedUser($organization, 'employee');

    $this->artisan('telegram:send-digest')->assertSuccessful();

    expect(TelegramReportDelivery::query()
        ->where('telegram_link_id', $link->id)
        ->where('report_type', 'financial_summary')
        ->exists())->toBeFalse();
});

test('an unlinked telegram_link (pending code, never completed) is skipped entirely', function () {
    $organization = Organization::factory()->create();
    CurrentOrganization::set($organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'current_organization_id' => $organization->id,
    ]);
    $user->assignRole('owner');

    $pendingLink = TelegramLink::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
    ]);
    expect($pendingLink->isLinked())->toBeFalse();

    $this->artisan('telegram:send-digest')->assertSuccessful();

    expect(TelegramReportDelivery::query()->where('telegram_link_id', $pendingLink->id)->exists())->toBeFalse();
});

test('one recipient failing to send never blocks or duplicates another recipient in the same run', function () {
    $organization = Organization::factory()->create();
    CurrentOrganization::set($organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);

    [$userA, $linkA] = makeLinkedUser($organization, 'employee');
    $userA->givePermissionTo('tasks.tasks.view');

    [$userB, $linkB] = makeLinkedUser($organization, 'employee');
    $userB->givePermissionTo('tasks.tasks.view');

    app(FakeTelegramTransport::class)->forceFailureFor($linkA->telegram_chat_id);

    $this->artisan('telegram:send-digest')->assertSuccessful();

    $deliveryA = TelegramReportDelivery::query()
        ->where('telegram_link_id', $linkA->id)->where('report_type', 'overdue_work')->first();
    $deliveryB = TelegramReportDelivery::query()
        ->where('telegram_link_id', $linkB->id)->where('report_type', 'overdue_work')->first();

    expect($deliveryA->status)->toBe('failed');
    expect($deliveryB->status)->toBe('sent');

    // A single run never creates more than one attempt per link/type,
    // whichever outcome it got.
    expect(TelegramReportDelivery::query()->where('telegram_link_id', $linkA->id)
        ->where('report_type', 'overdue_work')->count())->toBe(1);
});

test('two organizations are processed independently in one run with no cross-tenant leakage', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    CurrentOrganization::set($orgA->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($orgA->id);
    [$userA, $linkA] = makeLinkedUser($orgA, 'employee');
    $userA->givePermissionTo('tasks.tasks.view');

    CurrentOrganization::set($orgB->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($orgB->id);
    [$userB, $linkB] = makeLinkedUser($orgB, 'employee');
    $userB->givePermissionTo('tasks.tasks.view');

    CurrentOrganization::set($orgA->id);

    $this->artisan('telegram:send-digest')->assertSuccessful();

    // withoutTenantScope() here is a deliberate cross-tenant TEST assertion
    // (mirroring TenantIsolationRlsTest's own pattern for the same need) —
    // BelongsToOrganization's global scope filters every plain query by
    // CurrentOrganization::id(), which the command's own finally-block has
    // already restored to orgA by the time this line runs; without
    // bypassing the scope here, orgB's row would look "missing" even
    // though it's genuinely there, scoped correctly to orgB.
    $deliveryA = TelegramReportDelivery::query()->withoutTenantScope()->where('telegram_link_id', $linkA->id)->where('report_type', 'overdue_work')->first();
    $deliveryB = TelegramReportDelivery::query()->withoutTenantScope()->where('telegram_link_id', $linkB->id)->where('report_type', 'overdue_work')->first();

    expect($deliveryA)->not->toBeNull();
    expect($deliveryA->organization_id)->toBe($orgA->id);
    expect($deliveryB)->not->toBeNull();
    expect($deliveryB->organization_id)->toBe($orgB->id);

    expect(CurrentOrganization::id())->toBe($orgA->id);
});
