<?php

use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Attendance\Models\RawAccessEvent;
use App\Domain\Auth\Models\Organization;
use App\Domain\Devices\Actions\ConfirmExternalIdentifierMappingAction;
use App\Domain\Devices\Actions\IgnoreExternalIdentifierMappingAction;
use App\Domain\Devices\Actions\IngestRawAccessEventAction;
use App\Domain\Devices\Exceptions\DuplicateActiveCredentialAssignmentException;
use App\Domain\Devices\Exceptions\ExternalIdentifierMappingAlreadyResolvedException;
use App\Domain\Devices\Models\Credential;
use App\Domain\Devices\Models\CredentialAssignment;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\ExternalIdentifierMapping;
use App\Domain\Devices\Models\Site;
use App\Domain\Devices\Services\CardIdentifierNormalizer;
use App\Domain\Employees\Models\Employee;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

pest()->group('devices');

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

    $this->site = Site::factory()->create(['organization_id' => $this->organization->id]);
    $this->deviceIn = Device::factory()->create([
        'organization_id' => $this->organization->id,
        'site_id' => $this->site->id,
        'reader_role' => 'in',
    ]);
    $this->deviceOut = Device::factory()->create([
        'organization_id' => $this->organization->id,
        'site_id' => $this->site->id,
        'reader_role' => 'out',
    ]);
    $this->device = $this->deviceIn;
    $this->employee = Employee::factory()->create(['organization_id' => $this->organization->id]);
});

function unmatchedCardEvent(int $nativeId, string $time, string $hex = 'ABCDEF01'): array
{
    return [
        'native_event_id' => $nativeId,
        'stream_epoch' => 0,
        'raw_device_time' => $time,
        'event_code' => 'access_granted',
        'card_type' => 'EM',
        'card_hex' => $hex,
    ];
}

test('BIO-02: an unrecognized card never creates an Employee, and is recorded once for triage', function () {
    $event = app(IngestRawAccessEventAction::class)->execute($this->deviceIn, unmatchedCardEvent(1, '2026-09-20T05:00:00Z'));
    CurrentOrganization::set($this->organization->id);

    expect($event->credential_id)->toBeNull()
        ->and($event->unmatched_credential_ref)->toBe('EM:ABCDEF01')
        ->and(Employee::query()->count())->toBe(1); // only the beforeEach employee — none auto-created

    $mapping = ExternalIdentifierMapping::query()->sole();
    expect($mapping->status)->toBe('pending')
        ->and($mapping->external_type)->toBe('card')
        ->and($mapping->external_identifier)->toBe('EM:ABCDEF01');

    // A second swipe of the same still-unrecognized card must not spam a
    // second triage row.
    app(IngestRawAccessEventAction::class)->execute($this->deviceOut, unmatchedCardEvent(2, '2026-09-20T13:00:00Z'));
    CurrentOrganization::set($this->organization->id);

    expect(ExternalIdentifierMapping::query()->count())->toBe(1);
});

test('BIO-02: confirming a mapping links the card to the chosen employee and reprocesses correct attendance from the immutable raw history', function () {
    // Both timestamps are safely in the past relative to the real clock —
    // reprocessing's upper bound is now(), so a fixture timestamp later than
    // the actual current time would be silently excluded from the range.
    app(IngestRawAccessEventAction::class)->execute($this->deviceIn, unmatchedCardEvent(1, '2026-09-20T05:00:00Z'));
    CurrentOrganization::set($this->organization->id);
    app(IngestRawAccessEventAction::class)->execute($this->deviceOut, unmatchedCardEvent(2, '2026-09-20T13:00:00Z'));
    CurrentOrganization::set($this->organization->id);

    $rawEventsBefore = RawAccessEvent::query()->orderBy('native_event_id')->get(['id', 'credential_id', 'unmatched_credential_ref', 'payload_hash']);

    $mapping = ExternalIdentifierMapping::query()->sole();

    app(ConfirmExternalIdentifierMappingAction::class)->execute(
        mapping: $mapping,
        employee: $this->employee,
        validFrom: null,
        siteIds: null,
        actor: $this->owner,
    );
    CurrentOrganization::set($this->organization->id);

    $mapping->refresh();
    expect($mapping->status)->toBe('confirmed')
        ->and($mapping->target_type)->toBe(Credential::class)
        ->and($mapping->confirmed_by_user_id)->toBe($this->owner->id);

    $credential = Credential::query()->findOrFail($mapping->target_id);
    expect($credential->canonical_identifier)->not->toBeEmpty();

    // The raw events themselves are never rewritten — immutability holds.
    $rawEventsAfter = RawAccessEvent::query()->orderBy('native_event_id')->get(['id', 'credential_id', 'unmatched_credential_ref', 'payload_hash']);
    expect($rawEventsAfter->toArray())->toBe($rawEventsBefore->toArray());

    // Yet reconstruction now attributes them correctly, because it resolves
    // ownership via the confirmed mapping instead of the (permanently null)
    // credential_id column.
    $session = AttendanceSession::query()->where('employee_id', $this->employee->id)->sole();
    expect($session->status)->toBe('closed')
        ->and(Carbon::parse($session->clock_in_at)->utc()->toIso8601String())->toBe('2026-09-20T05:00:00+00:00')
        ->and($session->payable_minutes)->toBe(480);
});

test('BIO-02: a card already actively assigned to someone else cannot be silently reassigned via confirm', function () {
    app(IngestRawAccessEventAction::class)->execute($this->deviceIn, unmatchedCardEvent(1, '2026-09-20T05:00:00Z'));
    CurrentOrganization::set($this->organization->id);

    $otherEmployee = Employee::factory()->create(['organization_id' => $this->organization->id]);
    $normalized = app(CardIdentifierNormalizer::class)->fromHex('ABCDEF01', 'EM');
    $existingCredential = Credential::factory()->create([
        'organization_id' => $this->organization->id,
        'card_type' => 'EM',
        'canonical_identifier' => $normalized->canonicalIdentifier,
        'status' => 'issued',
    ]);
    CredentialAssignment::factory()->create([
        'organization_id' => $this->organization->id,
        'credential_id' => $existingCredential->id,
        'employee_id' => $otherEmployee->id,
        'status' => 'active',
        'valid_from' => now()->subYear(),
    ]);

    $mapping = ExternalIdentifierMapping::query()->sole();

    expect(fn () => app(ConfirmExternalIdentifierMappingAction::class)->execute(
        mapping: $mapping,
        employee: $this->employee,
        validFrom: null,
        siteIds: null,
        actor: $this->owner,
    ))->toThrow(DuplicateActiveCredentialAssignmentException::class);

    CurrentOrganization::set($this->organization->id);
    expect($mapping->fresh()->status)->toBe('pending');
});

test('BIO-02: confirming or ignoring an already-resolved mapping is rejected', function () {
    $mapping = ExternalIdentifierMapping::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => 'ignored',
    ]);

    expect(fn () => app(IgnoreExternalIdentifierMappingAction::class)->execute($mapping, null, $this->owner))
        ->toThrow(ExternalIdentifierMappingAlreadyResolvedException::class);
});

test('BIO-02: ignoring a mapping removes it from the default pending triage list without deleting it', function () {
    $mapping = ExternalIdentifierMapping::factory()->create(['organization_id' => $this->organization->id]);

    app(IgnoreExternalIdentifierMappingAction::class)->execute($mapping, 'სატესტო წვდომა', $this->owner);
    CurrentOrganization::set($this->organization->id);

    $this->actingAs($this->owner)
        ->get('/device-external-mappings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('mappings.data', 0));

    $this->actingAs($this->owner)
        ->get('/device-external-mappings?include_resolved=1')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('mappings.data', 1));

    CurrentOrganization::set($this->organization->id);
    expect(ExternalIdentifierMapping::query()->count())->toBe(1);
});

test('BIO-02: a user without manage permission is forbidden from confirming or ignoring', function () {
    $mapping = ExternalIdentifierMapping::factory()->create(['organization_id' => $this->organization->id]);
    $plainUser = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $plainUser->assignRole('employee');

    $this->actingAs($plainUser)
        ->post("/device-external-mappings/{$mapping->id}/confirm", ['employee_id' => $this->employee->id])
        ->assertForbidden();

    $this->actingAs($plainUser)
        ->post("/device-external-mappings/{$mapping->id}/ignore", [])
        ->assertForbidden();
});

test('BIO-02: a mapping belonging to another organization is not reachable', function () {
    $mapping = ExternalIdentifierMapping::factory()->create(['organization_id' => $this->organization->id]);

    $otherOrganization = Organization::factory()->create();
    CurrentOrganization::set($otherOrganization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($otherOrganization->id);
    $otherOwner = User::factory()->create([
        'organization_id' => $otherOrganization->id,
        'current_organization_id' => $otherOrganization->id,
    ]);
    $otherOwner->assignRole('owner');

    $this->actingAs($otherOwner)
        ->post("/device-external-mappings/{$mapping->id}/ignore", [])
        ->assertNotFound();
});
