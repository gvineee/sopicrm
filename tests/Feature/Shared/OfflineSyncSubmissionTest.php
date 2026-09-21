<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Notifications\Models\OfflineSyncSubmission;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * PWA-01: the /api/v1 offline-replay endpoints
 * (App\Http\Controllers\Api\V1\Tasks\OfflineSyncController) resources/js/lib/taskOfflineSync.ts
 * calls once connectivity returns. Every call must write exactly one
 * OfflineSyncSubmission row (the durable outcome record), and a repeated
 * Idempotency-Key must never double-apply the underlying action — the same
 * `idempotency` middleware contract already proven for money/stock POSTs
 * elsewhere in this codebase.
 */
pest()->group('shared', 'pwa');

beforeEach(function () {
    Storage::fake('private');
    $this->seed(AggregatingPermissionsSeeder::class);
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->performerUser = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->employee = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->performerUser->id,
    ]);
    $this->project = Project::factory()->create(['organization_id' => $this->organization->id]);
    $this->task = Task::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'accountable_owner_employee_id' => $this->employee->id,
        'status' => 'in_progress',
        'requires_photo_evidence' => false,
    ]);
});

test('an offline photo replay is applied and records a durable OfflineSyncSubmission row', function () {
    $key = (string) Str::uuid();

    $response = $this->actingAs($this->performerUser)
        ->postJson("/api/v1/projects/{$this->project->id}/tasks/{$this->task->id}/offline-attachments", [
            'file' => UploadedFile::fake()->image('evidence.jpg'),
            'client_item_id' => 'local-item-1',
        ], ['Idempotency-Key' => $key]);

    $response->assertCreated()->assertJson(['status' => 'applied']);

    CurrentOrganization::set($this->organization->id);
    $submission = OfflineSyncSubmission::query()->where('client_item_id', 'local-item-1')->sole();
    expect($submission->status)->toBe('applied')
        ->and($submission->kind)->toBe('photo')
        ->and($submission->resulting_attachment_id)->not->toBeNull();
});

test('replaying the same Idempotency-Key never double-applies the offline submission', function () {
    $key = (string) Str::uuid();
    $payload = [
        'comment' => 'დასრულებულია',
        'submitted_quantity' => null,
        'attachment_ids' => [],
        'client_item_id' => 'local-item-2',
    ];

    $first = $this->actingAs($this->performerUser)
        ->postJson("/api/v1/projects/{$this->project->id}/tasks/{$this->task->id}/offline-submissions", $payload, ['Idempotency-Key' => $key]);
    $first->assertCreated();

    CurrentOrganization::set($this->organization->id);
    expect(OfflineSyncSubmission::query()->where('client_item_id', 'local-item-2')->count())->toBe(1);
    expect($this->task->fresh()->status)->toBe('submitted');

    // A genuine network retry: identical key, identical body.
    $second = $this->actingAs($this->performerUser)
        ->postJson("/api/v1/projects/{$this->project->id}/tasks/{$this->task->id}/offline-submissions", $payload, ['Idempotency-Key' => $key]);
    $second->assertCreated()->assertHeader('Idempotency-Replayed', 'true');

    CurrentOrganization::set($this->organization->id);
    // Still exactly one OfflineSyncSubmission row and one real submission —
    // the replayed request never re-ran the Action at all.
    expect(OfflineSyncSubmission::query()->where('client_item_id', 'local-item-2')->count())->toBe(1);
});

test('a stale-state offline submission records a conflict row instead of silently succeeding', function () {
    $this->task->update(['status' => 'submitted']);

    $response = $this->actingAs($this->performerUser)
        ->postJson("/api/v1/projects/{$this->project->id}/tasks/{$this->task->id}/offline-submissions", [
            'comment' => 'late',
            'submitted_quantity' => null,
            'attachment_ids' => [],
            'client_item_id' => 'local-item-3',
        ], ['Idempotency-Key' => (string) Str::uuid()]);

    $response->assertStatus(409)->assertJson(['status' => 'conflict']);

    CurrentOrganization::set($this->organization->id);
    $submission = OfflineSyncSubmission::query()->where('client_item_id', 'local-item-3')->sole();
    expect($submission->status)->toBe('conflict');
});

test('a request missing the Idempotency-Key header is rejected before the action runs', function () {
    $this->actingAs($this->performerUser)
        ->postJson("/api/v1/projects/{$this->project->id}/tasks/{$this->task->id}/offline-submissions", [
            'comment' => 'x',
            'attachment_ids' => [],
        ])
        ->assertStatus(400);

    CurrentOrganization::set($this->organization->id);
    expect($this->task->fresh()->status)->toBe('in_progress');
});

test('a user who is not this task\'s performer is denied the offline endpoints directly', function () {
    $otherUser = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);

    $this->actingAs($otherUser)
        ->postJson("/api/v1/projects/{$this->project->id}/tasks/{$this->task->id}/offline-submissions", [
            'comment' => 'x',
            'attachment_ids' => [],
        ], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertForbidden();
});

test('a user from a different organization cannot reach the offline endpoints for this task', function () {
    $otherOrganization = Organization::factory()->create();
    CurrentOrganization::set($otherOrganization->id);
    $otherUser = User::factory()->create([
        'organization_id' => $otherOrganization->id,
        'current_organization_id' => $otherOrganization->id,
    ]);

    $this->actingAs($otherUser)
        ->postJson("/api/v1/projects/{$this->project->id}/tasks/{$this->task->id}/offline-submissions", [
            'comment' => 'x',
            'attachment_ids' => [],
        ], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertNotFound();
});
