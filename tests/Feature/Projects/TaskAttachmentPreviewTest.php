<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Auth\Models\ProjectMembership;
use App\Domain\Employees\Models\Employee;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Models\Attachment;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

/**
 * FILES-01: before this ticket, `TaskDetailResource` exposed attachment
 * metadata with no URL at all, and no route existed to actually stream a
 * task's own file — a reviewer could not open a submitted photo before
 * accepting it. These tests exercise the real protected endpoint, not just
 * that a URL string is present.
 */
pest()->group('projects', 'tasks');

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
    ]);
});

test('FILES-01: a task performer can open a photo they uploaded, and TaskDetailResource exposes a real preview URL', function () {
    $this->actingAs($this->performerUser)
        ->post("/projects/{$this->project->id}/tasks/{$this->task->id}/attachments", [
            'file' => UploadedFile::fake()->image('evidence.jpg'),
        ])
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $attachment = Attachment::query()->where('owner_type', Task::class)->where('owner_id', $this->task->id)->sole();
    Storage::disk('private')->assertExists($attachment->storage_path);

    $this->actingAs($this->performerUser)
        ->get("/projects/{$this->project->id}/tasks/{$this->task->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('task.attachments.0.url', fn ($url) => str_contains(
            $url,
            "/projects/{$this->project->id}/tasks/{$this->task->id}/attachments/{$attachment->id}",
        )));

    $this->actingAs($this->performerUser)
        ->get("/projects/{$this->project->id}/tasks/{$this->task->id}/attachments/{$attachment->id}")
        ->assertOk();
});

test('FILES-01: an attachment id from a different task is rejected, never served by another task\'s URL', function () {
    $this->actingAs($this->performerUser)
        ->post("/projects/{$this->project->id}/tasks/{$this->task->id}/attachments", [
            'file' => UploadedFile::fake()->image('mine.jpg'),
        ])
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $ownAttachment = Attachment::query()->where('owner_type', Task::class)->where('owner_id', $this->task->id)->sole();

    $otherTask = Task::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'accountable_owner_employee_id' => $this->employee->id,
    ]);

    // The performer can open their own task's attachment...
    $this->actingAs($this->performerUser)
        ->get("/projects/{$this->project->id}/tasks/{$this->task->id}/attachments/{$ownAttachment->id}")
        ->assertOk();

    // ...but the SAME attachment id can never be reached through a
    // different task's URL, even one they also perform.
    $this->actingAs($this->performerUser)
        ->get("/projects/{$this->project->id}/tasks/{$otherTask->id}/attachments/{$ownAttachment->id}")
        ->assertNotFound();
});

test('FILES-01: a manager can open a submission\'s re-owned evidence photo before accepting it', function () {
    $this->actingAs($this->performerUser)
        ->post("/projects/{$this->project->id}/tasks/{$this->task->id}/attachments", [
            'file' => UploadedFile::fake()->image('proof.jpg'),
        ])
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $attachment = Attachment::query()->where('owner_type', Task::class)->where('owner_id', $this->task->id)->sole();

    $this->actingAs($this->performerUser)
        ->post("/projects/{$this->project->id}/tasks/{$this->task->id}/submit", [
            'attachment_ids' => [$attachment->id],
        ])
        ->assertSessionHasNoErrors();

    CurrentOrganization::set($this->organization->id);
    // Re-owned to the submission — no longer directly on the Task.
    expect($attachment->fresh()->owner_type)->not->toBe(Task::class);

    $manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $manager->assignRole('project_manager');
    ProjectMembership::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'user_id' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->get("/projects/{$this->project->id}/tasks/{$this->task->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('task.submissions.0.photos.0.id', $attachment->id));

    $this->actingAs($manager)
        ->get("/projects/{$this->project->id}/tasks/{$this->task->id}/attachments/{$attachment->id}")
        ->assertOk();
});

test('FILES-01: a user from a different organization cannot reach the attachment even by guessing the id', function () {
    $this->actingAs($this->performerUser)
        ->post("/projects/{$this->project->id}/tasks/{$this->task->id}/attachments", [
            'file' => UploadedFile::fake()->image('secret.jpg'),
        ])
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $attachment = Attachment::query()->where('owner_type', Task::class)->where('owner_id', $this->task->id)->sole();

    $otherOrganization = Organization::factory()->create();
    CurrentOrganization::set($otherOrganization->id);
    $otherUser = User::factory()->create([
        'organization_id' => $otherOrganization->id,
        'current_organization_id' => $otherOrganization->id,
    ]);

    $this->actingAs($otherUser)
        ->get("/projects/{$this->project->id}/tasks/{$this->task->id}/attachments/{$attachment->id}")
        ->assertNotFound();
});
