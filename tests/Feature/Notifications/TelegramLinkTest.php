<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Notifications\Actions\CompleteTelegramLinkAction;
use App\Domain\Notifications\Actions\GenerateTelegramLinkCodeAction;
use App\Domain\Notifications\Actions\SendTelegramReportAction;
use App\Domain\Notifications\Adapters\FakeTelegramTransport;
use App\Domain\Notifications\Contracts\TelegramTransportInterface;
use App\Domain\Notifications\Exceptions\TelegramLinkCodeInvalidException;
use App\Domain\Notifications\Exceptions\TelegramReportNotAuthorizedException;
use App\Domain\Notifications\Models\TelegramLink;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

pest()->group('notifications', 'telegram');

beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->finance = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->finance->assignRole('finance');
});

test('a generated code can complete linking and an expired code is refused', function () {
    $link = app(GenerateTelegramLinkCodeAction::class)->execute($this->finance);
    expect($link->isLinked())->toBeFalse();

    $completed = app(CompleteTelegramLinkAction::class)->execute($link->link_code, 'chat-123');
    expect($completed->isLinked())->toBeTrue();
    expect($completed->telegram_chat_id)->toBe('chat-123');

    $link->update(['telegram_chat_id' => null, 'linked_at' => null, 'link_code_expires_at' => now()->subMinute()]);

    expect(fn () => app(CompleteTelegramLinkAction::class)->execute($link->link_code, 'chat-456'))
        ->toThrow(TelegramLinkCodeInvalidException::class);
});

test('attendance summary sends via the fake transport once linked and permitted', function () {
    $link = TelegramLink::factory()->linked()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->finance->id,
    ]);

    // finance does not hold attendance.sessions.view by default in this
    // codebase's role table — grant it directly to prove the permission
    // re-check reads real, current permissions rather than a hardcoded role.
    $this->finance->givePermissionTo('attendance.sessions.view');

    $delivery = app(SendTelegramReportAction::class)->execute($link, 'attendance_summary', $this->finance);

    expect($delivery->status)->toBe('sent');
    expect(app(FakeTelegramTransport::class)->sentMessages())->toHaveCount(1);
});

test('a demoted user is refused immediately, before any send is attempted', function () {
    $link = TelegramLink::factory()->linked()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->finance->id,
    ]);

    $this->finance->removeRole('finance');
    // finance role is what grants attendance.sessions.view path in this
    // test's setup; with no role/override left the user has nothing.

    expect(fn () => app(SendTelegramReportAction::class)->execute($link, 'attendance_summary', $this->finance))
        ->toThrow(TelegramReportNotAuthorizedException::class);

    expect(app(FakeTelegramTransport::class)->sentMessages())->toHaveCount(0);
});

test('financial summary requires access-financial-data (permission and confirmed 2FA), not just a role', function () {
    $link = TelegramLink::factory()->linked()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->finance->id,
    ]);

    // finance role already has finance.access (AuthPermissionsSeeder) but
    // no confirmed 2FA yet — access-financial-data must still refuse.
    expect(fn () => app(SendTelegramReportAction::class)->execute($link, 'financial_summary', $this->finance))
        ->toThrow(TelegramReportNotAuthorizedException::class);

    $this->finance->forceFill(['two_factor_confirmed_at' => now()])->save();

    $delivery = app(SendTelegramReportAction::class)->execute($link->fresh(), 'financial_summary', $this->finance->fresh());
    expect($delivery->status)->toBe('sent');
});

test('a provider failure is recorded and the same delivery can be retried once the transport recovers', function () {
    $link = TelegramLink::factory()->linked()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->finance->id,
    ]);
    $this->finance->givePermissionTo('attendance.sessions.view');

    app(FakeTelegramTransport::class)->forceFailureFor($link->telegram_chat_id);

    $delivery = app(SendTelegramReportAction::class)->execute($link, 'attendance_summary', $this->finance);
    expect($delivery->status)->toBe('failed');
    expect($delivery->failed_reason)->not->toBeNull();

    // Bind a fresh, non-failing fake for the retry — the point being proven
    // is that the SAME delivery row transitions failed -> sent, not that
    // this exact process-local double stops failing on its own.
    app()->instance(TelegramTransportInterface::class, new FakeTelegramTransport);

    $retried = app(SendTelegramReportAction::class)->retry($delivery->fresh());
    expect($retried->status)->toBe('sent');
});
