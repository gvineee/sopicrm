<?php

use App\Domain\Auth\Models\Organization;
use App\Domain\Employees\Models\Employee;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Models\Attachment;
use App\Domain\Shared\Services\CurrentOrganization;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskSubmission;
use App\Models\User;
use Database\Seeders\AggregatingPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

/**
 * WORKER-01: App\Http\Controllers\MyDayController replaces the static
 * `demoTasks` MyDay.vue previously rendered. These tests exercise the real
 * bucketing rule and the hard "never another employee's task" boundary
 * (reusing App\Policies\TaskPolicy::scopeVisibleToPerformer(), the same
 * scope FIX-02/A3 already proved correct for the dashboard).
 */
pest()->group('tasks');

beforeEach(function () {
    $this->seed(AggregatingPermissionsSeeder::class);
    $this->organization = Organization::factory()->create();
    CurrentOrganization::set($this->organization->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);

    $this->user = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);
    $this->user->assignRole('employee');
    $this->employee = Employee::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->user->id,
    ]);
    $this->project = Project::factory()->create(['organization_id' => $this->organization->id]);
});

test('WORKER-01: a plain employee sees their own tasks correctly bucketed into today, overdue, in review and returned', function () {
    $todayTask = Task::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'accountable_owner_employee_id' => $this->employee->id,
        'status' => 'in_progress',
        'title' => 'დღევანდელი დავალება',
    ]);

    $overdueTask = Task::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'accountable_owner_employee_id' => $this->employee->id,
        'status' => 'assigned',
        'due_at' => Carbon::yesterday(),
        'title' => 'ვადაგადაცილებული დავალება',
    ]);

    $inReviewTask = Task::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'accountable_owner_employee_id' => $this->employee->id,
        'status' => 'submitted',
        'title' => 'განსახილველი დავალება',
    ]);

    $returnedTask = Task::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'accountable_owner_employee_id' => $this->employee->id,
        'status' => 'in_progress',
        'title' => 'დაბრუნებული დავალება',
    ]);
    TaskSubmission::factory()->create([
        'organization_id' => $this->organization->id,
        'task_id' => $returnedTask->id,
        'submitted_by_employee_id' => $this->employee->id,
        'status' => 'returned',
        'returned_reason' => 'ფოტო არ არის საკმარისად ნათელი',
        'submitted_at' => now(),
    ]);

    // Someone else's task in the same project/org must never appear here.
    Task::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'status' => 'in_progress',
        'title' => 'სხვის თანამშრომელს დავალება',
    ]);

    $response = $this->actingAs($this->user)->get('/my-day')->assertOk();

    $response->assertInertia(fn ($page) => $page
        ->component('MyDay')
        ->where('hasEmployeeRecord', true)
        ->where('today.0.id', $todayTask->id)
        ->where('overdue.0.id', $overdueTask->id)
        ->where('inReview.0.id', $inReviewTask->id)
        ->where('returned.0.id', $returnedTask->id)
        ->where('returned.0.returnedReason', 'ფოტო არ არის საკმარისად ნათელი')
        ->has('today', 1)
        ->has('overdue', 1)
        ->has('inReview', 1)
        ->has('returned', 1));
});

test('WORKER-01: a user with no linked employee record sees an explicit empty state, not an error', function () {
    $userWithoutEmployee = User::factory()->create([
        'organization_id' => $this->organization->id,
        'current_organization_id' => $this->organization->id,
    ]);

    $this->actingAs($userWithoutEmployee)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('MyDay')->where('hasEmployeeRecord', false));
});

test('WORKER-01: starting a task from My Day\'s own action performs the real StartTask transition', function () {
    $task = Task::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'accountable_owner_employee_id' => $this->employee->id,
        'status' => 'assigned',
    ]);

    $this->actingAs($this->user)
        ->post("/projects/{$this->project->id}/tasks/{$task->id}/start")
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    expect($task->fresh()->status)->toBe('in_progress');
});

test('WORKER-01: an uploaded photo is reflected in My Day\'s own props so a real submission can reference it', function () {
    $task = Task::factory()->create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'accountable_owner_employee_id' => $this->employee->id,
        'status' => 'in_progress',
    ]);

    $this->actingAs($this->user)
        ->post("/projects/{$this->project->id}/tasks/{$task->id}/attachments", [
            'file' => UploadedFile::fake()->image('evidence.jpg'),
        ])
        ->assertRedirect();

    CurrentOrganization::set($this->organization->id);
    $attachment = Attachment::query()->where('owner_type', Task::class)->where('owner_id', $task->id)->sole();

    $this->actingAs($this->user)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('today.0.id', $task->id)
            ->where('today.0.attachments.0.id', $attachment->id));
});
